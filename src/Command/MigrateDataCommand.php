<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Command;

use Disstudio\EntityImport\DTO\EntityConfig;
use Disstudio\EntityImport\Service\EntityConfigProvider;
use Disstudio\EntityImport\Service\EntityMigrationService;
use Disstudio\EntityImport\Service\TableTruncateService;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-data',
)]
final class MigrateDataCommand
{
    public function __construct(
        private EntityMigrationService $entityMigrationService,
        private TableTruncateService $tableTruncateService,
        private EntityConfigProvider $entityConfigProvider,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        SymfonyStyle $io,
        #[Option(
            description: 'Migrate only specified entity by key from migration map configuration; all entities will be migrated if parameter is omitted.',
            name: 'key',
            shortcut: 'k',
        )]
        ?string $key = null,
        #[Option(
            description: 'Ignore previous progress and start from the first record',
            name: 'ignore-progress',
        )]
        bool $ignoreProgress = false,
        #[Option(
            description: 'Truncate table (clear all records) before processing',
            name: 'truncate',
        )]
        bool $truncate = false,
    ): int {
        $migrationKey = $key;

        /** @var EntityConfig[] $entityConfigArray */
        $entityConfigArray = ($migrationKey !== null) ?
            [$migrationKey => $this->entityConfigProvider->getByKey($migrationKey)] :
            $this->entityConfigProvider->getEntityConfigArray();

        if ($truncate) {
            if ($migrationKey !== null) {
                $io->error('Cannot specify both --key and --truncate options');
                return Command::INVALID;
            }

            foreach (array_reverse($entityConfigArray) as $entityConfig) {
                $io->write(sprintf('Truncating table for %s...', $entityConfig->getTargetEntityClass()));
                $this->tableTruncateService->truncateTargetTable($entityConfig);
                $io->write('Success');
                $io->newLine();
            }
        }

        try {
            foreach ($entityConfigArray as $migrationKey => $entityConfig) {
                $io->writeln(sprintf('Migrating %s:', $migrationKey));

                $section = null;
                if ($output instanceof ConsoleOutputInterface) {
                    $section = $output->section();
                }

                $rowsMigrated = 0;
                $rowsSkipped = 0;

                $ignoreProgressCurrent = $ignoreProgress;
                do {
                    $result = $this->entityMigrationService->migrateEntityChunk($migrationKey, $ignoreProgressCurrent);
                    $ignoreProgressCurrent = false;

                    $section?->overwrite(
                        sprintf(
                            'Migrated %d entities, %d skipped',
                            $rowsMigrated += $result->getRowsMigrated(),
                            $rowsSkipped += $result->getRowsSkipped()
                        )
                    );
                } while ($result->getRowsTotal() > 0);
            }
        } catch (Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Migrated successfully!');

        return Command::SUCCESS;
    }
}
