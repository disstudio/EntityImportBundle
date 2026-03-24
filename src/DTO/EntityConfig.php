<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\DTO;

use Disstudio\EntityImport\Service\EntityMigrationService;
use LogicException;

/**
 * @phpstan-import-type MigrationMapShape from EntityMigrationService
 */
final readonly class EntityConfig
{
    public static function createFromArrayConfig(array $config): self
    {
        $legacyTable = $config['source_table'] ?? throw new LogicException('Migration map is not properly configured.');
        /** @var class-string $targetEntityClass */
        $targetEntityClass = $config['target_entity'] ?? throw new LogicException('Migration map is not properly configured.');
        $factoryServiceId = $config['factory'] ?? throw new LogicException('Migration map is not properly configured.');

        $legacyPk = 'id';
        $legacyIdentifier = $config['source_identifier'] ?? 'id';
        $targetIdentifier = $config['target_identifier'] ?? 'id';

        $foreignKeys = $config['foreign_keys'] ?? [];

        return new self(
            $legacyTable,
            $targetEntityClass,
            $factoryServiceId,
            $legacyPk,
            $legacyIdentifier,
            $targetIdentifier,
            $foreignKeys
        );
    }

    /**
     * @param class-string $targetEntityClass
     * @param string[] $foreignKeys
     */
    public function __construct(
        private string $legacyTable,
        private string $targetEntityClass,
        private string $factoryServiceId,
        private string $legacyPk,
        private string $legacyIdentifier,
        private string $targetIdentifier,
        private array $foreignKeys,
    ) {
    }

    public function getLegacyTable(): string
    {
        return $this->legacyTable;
    }

    /**
     * @return class-string
     */
    public function getTargetEntityClass(): string
    {
        return $this->targetEntityClass;
    }

    public function getFactoryServiceId(): string
    {
        return $this->factoryServiceId;
    }

    public function getLegacyPk(): string
    {
        return $this->legacyPk;
    }

    public function getLegacyIdentifier(): string
    {
        return $this->legacyIdentifier;
    }

    public function getTargetIdentifier(): string
    {
        return $this->targetIdentifier;
    }

    /**
     * @return string[]
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }
}
