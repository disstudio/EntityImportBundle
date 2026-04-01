<?php

namespace Disstudio\EntityImport\DTO;

use LogicException;

final readonly class ForeignKeyConfig
{
    public static function createFromArrayConfig(array $config): self
    {
        $localIdentifier = $config['local_identifier'] ?? throw new LogicException('Foreign_keys.local_identifier is not properly configured');
        $targetKey = $config['target_key'] ?? throw new LogicException('Foreign_keys.target_key is not properly configured');
        $joinTable = $config['join_table'] ?? null;
        $foreignIdentifier = $config['foreign_identifier'] ?? null;

        return new self(
            $localIdentifier,
            $targetKey,
            $joinTable,
            $foreignIdentifier,
        );
    }

    public function __construct(
        private string $localIdentifier,
        private string $targetKey,
        private ?string $joinTable,
        private ?string $foreignIdentifier,
    )
    {
    }

    public function getLocalIdentifier(): string
    {
        return $this->localIdentifier;
    }

    public function getTargetKey(): string
    {
        return $this->targetKey;
    }

    public function getJoinTable(): ?string
    {
        return $this->joinTable;
    }

    public function getForeignIdentifier(): ?string
    {
        return $this->foreignIdentifier;
    }
}
