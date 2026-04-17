<?php

namespace Disstudio\EntityImport\Service;

use Disstudio\EntityImport\DTO\EntityConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TableTruncateService
{
    private Connection $connection;

    private ObjectManager $manager;

    public function __construct(
        private ManagerRegistry $doctrine,
        #[Autowire(param: 'disstudio_entity_import.target_connection')]
        string $targetConnectionName,
    )
    {
        /** @var Connection $sourceConnection */
        $sourceConnection = $this->doctrine->getConnection($targetConnectionName);
        $this->connection = $sourceConnection;

        $this->manager = $this->doctrine->getManager($targetConnectionName);
    }

    public function truncateTargetTable(EntityConfig $entityConfig): void
    {
        $classMetadata = $this->manager->getClassMetadata($entityConfig->getTargetEntityClass());
        $platform = $this->connection->getDatabasePlatform();
        $quotedTableName = $this->connection->quoteSingleIdentifier($classMetadata->getTableName());

        if ($platform instanceof PostgreSQLPlatform) {
            // Postgres supports cascading truncation natively
            $this->connection->executeStatement(
                sprintf('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', $quotedTableName)
            );

        } elseif ($platform instanceof AbstractMySQLPlatform) {
            // MySQL/MariaDB requires disabling foreign key checks temporarily
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0;');

            try {
                $sql = sprintf('TRUNCATE TABLE %s', $quotedTableName);
                $this->connection->executeStatement($sql);
            } finally {
                // Ensure checks are turned back on even if the truncate fails
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1;');
            }

        } else {
            // Fallback for other platforms (e.g., SQLite)
            // Note: SQLite doesn't have a direct TRUNCATE command, it uses DELETE FROM
            $sql = $platform->getTruncateTableSQL($quotedTableName);
            $this->connection->executeStatement($sql);
        }
    }
}
