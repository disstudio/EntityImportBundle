<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Service;

use Disstudio\EntityImport\DependencyInjection\DisstudioEntityImportExtension;
use Disstudio\EntityImport\DTO\ChunkResult;
use Disstudio\EntityImport\DTO\EntityConfig;
use Disstudio\EntityImport\Entity\MigrationStatus;
use Disstudio\EntityImport\Factory\FactoryInterface;
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

/**
 * @phpstan-type MigrationMapShape array{'sourceTable': string,
 *     'sourceIdentifier'?: string,
 *     'targetIdentifier'?: string,
 *     'targetEntity': string,
 *     'factory': string,
 *     'foreignKeys': array{string}
 * }
 */
final readonly class EntityMigrationService
{
    private EntityManagerInterface $entityManager;

    private Connection $sourceConnection;

    public function __construct(
        private ManagerRegistry $doctrine,
        #[AutowireLocator(DisstudioEntityImportExtension::FACTORY_SERVICE_TAG)]
        private ContainerInterface $factoryLocator,
        /** @var MigrationMapShape[] $migrationMap */
        #[Autowire(param: 'disstudio_entity_import.entity_map')]
        private array $migrationMap,
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

    public function migrateEntityChunk(string $migrationKey): ChunkResult
    {
        $entityConfig = $this->getEntityConfig($migrationKey);

        $sourceQueryBuilder = $this->sourceConnection->createQueryBuilder()
            ->select(sprintf('%s.*', $entityConfig->getLegacyTable()))
            ->from($entityConfig->getLegacyTable())
            ->setMaxResults($this->chunkSize)
            ->orderBy(sprintf('%s.%s', $entityConfig->getLegacyTable(), $entityConfig->getLegacyPk()));

        $sourceMigrationStatus = $this->getLegacyMigrationStatus($migrationKey);
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

        foreach ($entityConfig->getForeignKeys() as $sourceFkField => $fkMigrationKey) {
            $fkEntityConfig = $this->getEntityConfig($fkMigrationKey);
            $fkFieldAlias = sprintf('%s_%s', $fkMigrationKey, $fkEntityConfig->getLegacyIdentifier());
            $fkFieldAliases[$fkMigrationKey] = $fkFieldAlias;

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

        $targetEntityFactory = $this->getFactory($entityConfig->getFactoryServiceId());

        $sourceQueryResult = $sourceQueryBuilder->fetchAllAssociative();
        /** @var int[]|string[] $sourceIds */
        $sourceIds = array_column($sourceQueryResult, $entityConfig->getLegacyIdentifier());

        $targetEntityArray = $this->getTargetEntityArray(
            $entityConfig->getTargetEntityClass(),
            $entityConfig->getTargetIdentifier(),
            $sourceIds
        );

        foreach ($entityConfig->getForeignKeys() as $fkMigrationKey) {
            $fkEntityConfig = $this->getEntityConfig($fkMigrationKey);

            $fkTargetEntities[$fkMigrationKey] = $this->getTargetEntityArray(
                $fkEntityConfig->getTargetEntityClass(),
                $fkEntityConfig->getTargetIdentifier(),
                /** @phpstan-ignore-next-line */
                array_unique(array_column($sourceQueryResult, $fkFieldAliases[$fkMigrationKey]))
            );
        }

        /** @var int|null $lastLegacyEntityId */
        $lastLegacyEntityId = null;
        $rowsMigrated = 0;
        $rowsSkipped = 0;

        /** @var scalar[] $row */
        foreach ($sourceQueryResult as $row) {
            /** @var int|string $sourceId */
            $sourceId = $row[$entityConfig->getLegacyIdentifier()];
            /* @phpstan-ignore-next-line */
            $targetEntity = $targetEntityArray[$sourceId] ?? null;
            $lastLegacyEntityId = $row[$entityConfig->getLegacyPk()];

            if ($targetEntity === null) {
                // Add fk entities to result target rows
                foreach ($entityConfig->getForeignKeys() as $fkMigrationKey) {
                    /** @var int|string $fkTargetId */
                    $fkTargetId = $row[$fkFieldAliases[$fkMigrationKey]];
                    $row[$fkMigrationKey] = $fkTargetEntities[$fkMigrationKey][$fkTargetId] ?? null;
                }

                $targetEntity = $targetEntityFactory->createFromArray($row);
                $this->entityManager->persist($targetEntity);

                // handle the case if entity fk references to entity itself
                foreach ($entityConfig->getForeignKeys() as $fkMigrationKey) {
                    if (
                        $fkMigrationKey === $migrationKey &&
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

    private function getEntityConfig(string $migrationKey): EntityConfig
    {
        if (!array_key_exists($migrationKey, $this->migrationMap)) {
            throw new InvalidArgumentException(sprintf('Migration key "%s" does not exist.', $migrationKey));
        }

        return EntityConfig::createFromArrayConfig($this->migrationMap[$migrationKey]);
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
