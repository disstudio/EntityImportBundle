<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Service;

use Disstudio\EntityImport\DependencyInjection\DisstudioEntityImportExtension;
use Disstudio\EntityImport\DTO\ChunkResult;
use Disstudio\EntityImport\DTO\EntityConfig;
use Disstudio\EntityImport\Entity\LegacyMigrationStatus;
use Disstudio\EntityImport\Factory\FactoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * @phpstan-type MigrationMapShape array{'legacyTable': string,
 *     'legacyIdentifier'?: string,
 *     'targetIdentifier'?: string,
 *     'targetEntity': string,
 *     'factory': string,
 *     'foreignKeys': array{string}
 * }
 */
final readonly class EntityMigrationService
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private EntityManagerInterface $entityManager,
        #[AutowireLocator(DisstudioEntityImportExtension::FACTORY_SERVICE_TAG)]
        private ContainerInterface $factoryLocator,
        /** @var MigrationMapShape[] $migrationMap */
        #[Autowire(param: 'legacy_migration_entity_map')]
        private array $migrationMap,
        #[Autowire(param: 'legacy_migration_chunk_size')]
        private int $chunkSize,
    ) {
    }

    public function migrateEntityChunk(string $migrationKey): ChunkResult
    {
        $entityConfig = $this->getEntityConfig($migrationKey);

        /** @var Connection $legacyConn */
        $legacyConn = $this->doctrine->getConnection('legacy');

        $legacyQueryBuilder = $legacyConn->createQueryBuilder()
            ->select(sprintf('%s.*', $entityConfig->getLegacyTable()))
            ->from($entityConfig->getLegacyTable())
            ->setMaxResults($this->chunkSize)
            ->orderBy(sprintf('%s.%s', $entityConfig->getLegacyTable(), $entityConfig->getLegacyPk()));

        $legacyMigrationStatus = $this->getLegacyMigrationStatus($migrationKey);
        if ($legacyMigrationStatus) {
            $legacyQueryBuilder
                ->andWhere(sprintf('%s.%s > :last_id', $entityConfig->getLegacyTable(), $entityConfig->getLegacyPk()))
                ->setParameter('last_id', $legacyMigrationStatus->getLastId());
        } else {
            $legacyMigrationStatus = new LegacyMigrationStatus($migrationKey);
            $this->entityManager->persist($legacyMigrationStatus);
        }

        $fkTargetEntities = [];
        $fkFieldAliases = [];

        foreach ($entityConfig->getForeignKeys() as $legacyFkField => $fkMigrationKey) {
            $fkEntityConfig = $this->getEntityConfig($fkMigrationKey);
            $fkFieldAlias = sprintf('%s_%s', $fkMigrationKey, $fkEntityConfig->getLegacyIdentifier());
            $fkFieldAliases[$fkMigrationKey] = $fkFieldAlias;

            $legacyQueryBuilder->leftJoin(
                $entityConfig->getLegacyTable(),
                $fkEntityConfig->getLegacyTable(),
                $fkMigrationKey,
                $legacyQueryBuilder->expr()->eq(
                    sprintf('%s.%s', $entityConfig->getLegacyTable(), $legacyFkField),
                    sprintf('%s.%s', $fkMigrationKey, $fkEntityConfig->getLegacyPk())
                )
            );
            $legacyQueryBuilder->addSelect(sprintf(
                '%s.%s AS %s',
                $fkMigrationKey,
                $fkEntityConfig->getLegacyIdentifier(),
                $fkFieldAlias
            ));
        }

        $targetEntityFactory = $this->getFactory($entityConfig->getFactoryServiceId());

        $legacyQueryResult = $legacyQueryBuilder->fetchAllAssociative();
        /** @var int[]|string[] $legacyIds */
        $legacyIds = array_column($legacyQueryResult, $entityConfig->getLegacyIdentifier());

        $targetEntityArray = $this->getTargetEntityArray(
            $entityConfig->getTargetEntityClass(),
            $entityConfig->getTargetIdentifier(),
            $legacyIds
        );

        foreach ($entityConfig->getForeignKeys() as $fkMigrationKey) {
            $fkEntityConfig = $this->getEntityConfig($fkMigrationKey);

            $fkTargetEntities[$fkMigrationKey] = $this->getTargetEntityArray(
                $fkEntityConfig->getTargetEntityClass(),
                $fkEntityConfig->getTargetIdentifier(),
                /** @phpstan-ignore-next-line */
                array_unique(array_column($legacyQueryResult, $fkFieldAliases[$fkMigrationKey]))
            );
        }

        /** @var int|null $lastLegacyEntityId */
        $lastLegacyEntityId = null;
        $rowsMigrated = 0;
        $rowsSkipped = 0;

        /** @var scalar[] $row */
        foreach ($legacyQueryResult as $row) {
            /* @phpstan-ignore-next-line */
            $targetEntity = $targetEntityArray[$row[$entityConfig->getLegacyIdentifier()]] ?? null;
            $lastLegacyEntityId = $row[$entityConfig->getLegacyPk()];

            if ($targetEntity === null) {
                // Add fk entities to result target rows
                foreach ($entityConfig->getForeignKeys() as $fkMigrationKey) {
                    /** @var int|string $fkTargetIdentifier */
                    $fkTargetIdentifier = $row[$fkFieldAliases[$fkMigrationKey]];
                    $row[$fkMigrationKey] = $fkTargetEntities[$fkMigrationKey][$fkTargetIdentifier] ?? null;
                }

                $targetEntity = $targetEntityFactory->createFromArray($row);
                $this->entityManager->persist($targetEntity);

                ++$rowsMigrated;
            } else {
                ++$rowsSkipped;
            }
        }

        $this->entityManager->flush();

        if ($lastLegacyEntityId !== null) {
            $legacyMigrationStatus->setLastId((int) $lastLegacyEntityId);
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

    private function getLegacyMigrationStatus(string $migrationKey): ?LegacyMigrationStatus
    {
        return $this->entityManager
            ->getRepository(LegacyMigrationStatus::class)
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
     * @param int[]|string[] $legacyIds
     *
     * @return object[]
     */
    private function getTargetEntityArray(
        string $targetEntityClass,
        string $targetIdentifier,
        array $legacyIds,
    ): array {
        $targetEntityRepository = $this->entityManager->getRepository($targetEntityClass);

        /** @phpstan-ignore-next-line */
        return $targetEntityRepository
            ->createQueryBuilder('t', 't.' . $targetIdentifier)
            ->where(sprintf('t.%s IN (:ids)', $targetIdentifier))
            ->setParameter('ids', $legacyIds)
            ->getQuery()
            ->getResult();
    }
}
