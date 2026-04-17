<?php

namespace Disstudio\EntityImport\Service;


use Disstudio\EntityImport\DTO\EntityConfig;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type MigrationMapShape array{'sourceTable': string,
 *     'sourceIdentifier'?: string,
 *     'targetIdentifier'?: string,
 *     'targetEntity': string,
 *     'factory': string,
 *     'foreignKeys': array{string, array{'local_identifier', 'target_key', 'join_table', 'foreign_identifier'}}
 * }
 */
final readonly class EntityConfigProvider
{
    private array $entityConfigArray;

    public function __construct(
        /** @var MigrationMapShape[] $migrationMap */
        #[Autowire(param: 'disstudio_entity_import.entity_map')]
        array $migrationMap,
        private TableDependencyResolverService $tableDependencyResolverService,
    )
    {
        $entityConfigArray = array_map(
            static fn (array $item) => EntityConfig::createFromArrayConfig($item),
            $migrationMap
        );

        $this->entityConfigArray = $this->tableDependencyResolverService->resolveDependencies($entityConfigArray);
    }

    /**
     * @return EntityConfig[]
     */
    public function getEntityConfigArray(): array
    {
        return $this->entityConfigArray;
    }

    public function getByKey(string $migrationKey): EntityConfig
    {
        if (!array_key_exists($migrationKey, $this->entityConfigArray)) {
            throw new InvalidArgumentException(sprintf('Migration key "%s" does not exist.', $migrationKey));
        }

        return $this->entityConfigArray[$migrationKey];
    }
}
