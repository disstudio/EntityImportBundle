<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Command;

use Disstudio\EntityImport\Service\EntityMigrationService;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-import-type MigrationMapShape from EntityMigrationService
 */
#[AsCommand(
    name: 'app:migrate-data',
)]
final class MigrateDataCommand
{
    public function __construct(
        private EntityMigrationService $entityMigrationService,
        /** @var MigrationMapShape[] $migrationMap */
        #[Autowire(param: 'disstudio_entity_import.entity_map')]
        private array $migrationMap,
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
    ): int {
        $migrationKey = $key;

        /** @var string[] $migrationKeyArray */
        $migrationKeyArray = ($migrationKey !== null) ? [$migrationKey] : array_keys($this->migrationMap);

        try {
            foreach ($migrationKeyArray as $migrationKeyItem) {
                $io->writeln(sprintf('Migrating %s:', $migrationKeyItem));

                $section = null;
                if ($output instanceof ConsoleOutputInterface) {
                    $section = $output->section();
                }

                $rowsMigrated = 0;
                $rowsSkipped = 0;

                $ignoreProgressCurrent = $ignoreProgress;
                do {
                    $result = $this->entityMigrationService->migrateEntityChunk($migrationKeyItem, $ignoreProgressCurrent);
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
