<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Service;

use Disstudio\EntityImport\DependencyInjection\DisstudioEntityImportExtension;
use Disstudio\EntityImport\DTO\ChunkResult;
use Disstudio\EntityImport\DTO\EntityConfig;
use Disstudio\EntityImport\Entity\MigrationStatus;
use Disstudio\EntityImport\Exception\FactoryException;
use Disstudio\EntityImport\Factory\FactoryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class EntityMigrationService
{
    private EntityManagerInterface $entityManager;

    private Connection $sourceConnection;

    public function __construct(
        private ManagerRegistry $doctrine,
        #[AutowireLocator(DisstudioEntityImportExtension::FACTORY_SERVICE_TAG)]
        private ContainerInterface $factoryLocator,
        private EntityConfigProvider $entityConfigProvider,
        #[Autowire(param: 'disstudio_entity_import.chunk_size')]
        private int $chunkSize,
        #[Autowire(param: 'disstudio_entity_import.source_connection')]
        string $sourceConnectionName,
        #[Autowire(param: 'disstudio_entity_import.target_connection')]
        string $targetConnectionName,
    ) {
        $this->entityManager = $this->doctrine->getManager($targetConnectionName);

        /** @var Connection $sourceConnection */
        $sourceConnection = $this->doctrine->getConnection($sourceConnectionName);
        $this->sourceConnection = $sourceConnection;
    }

    public function migrateEntityChunk(string $migrationKey, bool $ignoreProgress = false): ChunkResult
    {
        $entityConfig = $this->entityConfigProvider->getByKey($migrationKey);

        $sourceQueryBuilder = $this->sourceConnection->createQueryBuilder()
            ->select(sprintf('%s.*', $entityConfig->getLegacyTable()))
            ->from($entityConfig->getLegacyTable())
            ->setMaxResults($this->chunkSize)
            ->orderBy(sprintf('%s.%s', $entityConfig->getLegacyTable(), $entityConfig->getLegacyPk()));

        $sourceMigrationStatus = $ignoreProgress ? null : $this->getLegacyMigrationStatus($migrationKey);
        if ($sourceMigrationStatus) {
            $sourceQueryBuilder
                ->andWhere(sprintf('%s.%s > :last_id', $entityConfig->getLegacyTable(), $entityConfig->getLegacyPk()))
                ->setParameter('last_id', $sourceMigrationStatus->getLastId());
        } else {
            $sourceMigrationStatus = new MigrationStatus($migrationKey);
            $this->entityManager->persist($sourceMigrationStatus);
        }

        $fkTargetEntities = [];
        $fkFieldAliases = [];

        foreach ($entityConfig->getForeignKeys() as $fkMigrationKey => $fkConfig) {
            $sourceFkField = $fkConfig->getLocalIdentifier();
            $fkEntityConfig = $this->entityConfigProvider->getByKey($fkConfig->getTargetKey());
            $fkFieldAlias = sprintf('%s_%s', $fkMigrationKey, $fkEntityConfig->getLegacyIdentifier());
            $fkFieldAliases[$fkMigrationKey] = $fkFieldAlias;

            if (null === $fkConfig->getJoinTable()) {
                // one-to-many relation (without join table)
                $sourceQueryBuilder->leftJoin(
                    $entityConfig->getLegacyTable(),
                    $fkEntityConfig->getLegacyTable(),
                    $fkMigrationKey,
                    $sourceQueryBuilder->expr()->eq(
                        sprintf('%s.%s', $entityConfig->getLegacyTable(), $sourceFkField),
                        sprintf('%s.%s', $fkMigrationKey, $fkEntityConfig->getLegacyPk())
                    )
                );
                $sourceQueryBuilder->addSelect(sprintf(
                    '%s.%s AS %s',
                    $fkMigrationKey,
                    $fkEntityConfig->getLegacyIdentifier(),
                    $fkFieldAlias
                ));
            }
        }

        $sourceQueryResult = $sourceQueryBuilder->fetchAllAssociative();
        /** @var int[]|string[] $sourceIds */
        $sourceIds = array_unique(array_column($sourceQueryResult, $entityConfig->getLegacyIdentifier()));

        $targetEntityArray = $this->getTargetEntityArray(
            $entityConfig->getTargetEntityClass(),
            $entityConfig->getTargetIdentifier(),
            $sourceIds
        );

        foreach ($entityConfig->getForeignKeys() as $fkMigrationKey => $fkConfig) {
            $fkEntityConfig = $this->entityConfigProvider->getByKey($fkConfig->getTargetKey());

            if (null === $fkConfig->getJoinTable()) {
                // one-to-many relation (without join table)
                $fkTargetEntities[$fkMigrationKey] = $this->getTargetEntityArray(
                    $fkEntityConfig->getTargetEntityClass(),
                    $fkEntityConfig->getTargetIdentifier(),
                    /** @phpstan-ignore-next-line */
                    array_unique(array_column($sourceQueryResult, $fkFieldAliases[$fkMigrationKey]))
                );
            } else {
                // many-to-many relation (with join table)
                $relationQueryBuilder = $this->sourceConnection->createQueryBuilder()
                    ->from($fkConfig->getJoinTable())
                    ->leftJoin(
                        $fkConfig->getJoinTable(),
                        $fkEntityConfig->getLegacyTable(),
                        $fkMigrationKey,
                        $sourceQueryBuilder->expr()->eq(
                            sprintf('%s.%s', $fkConfig->getJoinTable(), $fkConfig->getForeignIdentifier()),
                            sprintf('%s.%s', $fkMigrationKey, $fkEntityConfig->getLegacyPk())
                        )
                    )
                    ->leftJoin(
                        $fkConfig->getJoinTable(),
                        $entityConfig->getLegacyTable(),
                        't',
                        $sourceQueryBuilder->expr()->eq(
                            sprintf('%s.%s', $fkConfig->getJoinTable(), $fkConfig->getLocalIdentifier()),
                            sprintf('t.%s', $entityConfig->getLegacyPk())
                        )
                    )
                    ->select(
                        sprintf('t.%s AS p_id', $entityConfig->getLegacyIdentifier()),
                        sprintf('%s.%s AS f_id', $fkMigrationKey, $fkEntityConfig->getLegacyIdentifier()),
                    )
                    ->where(sprintf('t.%s IN(:ids)', $entityConfig->getLegacyIdentifier()))
                    ->setParameter('ids', $sourceIds, ArrayParameterType::STRING);

                /** @var array{array{pk: string|int, fk: string|int}} $relationData */
                $relationData = $relationQueryBuilder->fetchAllAssociative();
                $relationIds = array_unique(array_column($relationData, 'f_id'));
                $relationTargetEntityArray = $this->getTargetEntityArray(
                    $fkEntityConfig->getTargetEntityClass(),
                    $fkEntityConfig->getTargetIdentifier(),
                    /** @phpstan-ignore-next-line */
                    $relationIds
                );
                foreach ($relationData as $result) {
                    $fkTargetEntities[$fkMigrationKey][$result['p_id']][] = $relationTargetEntityArray[$result['f_id']];
                }
            }
        }

        /** @var int|null $lastLegacyEntityId */
        $lastLegacyEntityId = null;
        $rowsMigrated = 0;
        $rowsSkipped = 0;

        $targetEntityFactory = $this->getFactory($entityConfig->getFactoryServiceId());

        /** @var scalar[] $row */
        foreach ($sourceQueryResult as $row) {
            /** @var int|string $sourceId */
            $sourceId = $row[$entityConfig->getLegacyIdentifier()];

            /* @phpstan-ignore-next-line */
            $targetEntity = $targetEntityArray[$sourceId] ?? null;
            $lastLegacyEntityId = $row[$entityConfig->getLegacyPk()];

            if ($targetEntity === null) {
                // Add fk entities to result target rows
                foreach ($entityConfig->getForeignKeys() as $fkMigrationKey => $fkConfig) {
                    if (null === $fkConfig->getJoinTable()) {
                        /** @var int|string $fkTargetId */
                        $fkTargetId = $row[$fkFieldAliases[$fkMigrationKey]];
                        $row[$fkMigrationKey] = $fkTargetEntities[$fkMigrationKey][$fkTargetId] ?? null;
                    } else {
                        $sourceRelationPrimaryId = $row[$entityConfig->getLegacyIdentifier()];
                        if (array_key_exists($sourceRelationPrimaryId, $fkTargetEntities[$fkMigrationKey])) {
                            $row[$fkMigrationKey] = $fkTargetEntities[$fkMigrationKey][$sourceRelationPrimaryId];
                        } else {
                            $row[$fkMigrationKey] = [];
                        }
                    }
                }

                try {
                    $targetEntity = $targetEntityFactory->createFromArray($row);
                } catch (\Throwable $t) {
                    throw FactoryException::fromThrowable($t);
                }
                $this->entityManager->persist($targetEntity);

                // handle the case if entity fk references to entity itself
                foreach ($entityConfig->getForeignKeys() as $fkMigrationKey => $fkConfig) {
                    if (
                        $fkConfig->getTargetKey() === $migrationKey &&
                        (null === $fkConfig->getJoinTable()) &&
                        !array_key_exists($sourceId, $fkTargetEntities[$fkMigrationKey])
                    ) {
                        $fkTargetEntities[$fkMigrationKey][$sourceId] = $targetEntity;
                    }
                }

                ++$rowsMigrated;
            } else {
                ++$rowsSkipped;
            }
        }

        $this->entityManager->flush();

        if ($lastLegacyEntityId !== null) {
            $sourceMigrationStatus->setLastId((int) $lastLegacyEntityId);
            $this->entityManager->flush();
        }

        return new ChunkResult($rowsMigrated, $rowsSkipped);
    }

    private function getLegacyMigrationStatus(string $migrationKey): ?MigrationStatus
    {
        return $this->entityManager
            ->getRepository(MigrationStatus::class)
            ->findOneBy(['key' => $migrationKey]);
    }

    /**
     * @return FactoryInterface<object>
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function getFactory(string $serviceId): FactoryInterface
    {
        /** @var FactoryInterface<object> $factory */
        $factory = $this->factoryLocator->get($serviceId);

        return $factory;
    }

    /**
     * @param class-string $targetEntityClass
     * @param int[]|string[] $sourceIds
     *
     * @return object[]
     */
    private function getTargetEntityArray(
        string $targetEntityClass,
        string $targetIdentifier,
        array $sourceIds,
    ): array {
        $targetEntityRepository = $this->entityManager->getRepository($targetEntityClass);

        /** @phpstan-ignore-next-line */
        return $targetEntityRepository
            ->createQueryBuilder('t', 't.' . $targetIdentifier)
            ->where(sprintf('t.%s IN (:ids)', $targetIdentifier))
            ->setParameter('ids', $sourceIds)
            ->getQuery()
            ->getResult();
    }
}
