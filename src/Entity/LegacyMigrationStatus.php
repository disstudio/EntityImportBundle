<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_legacy_migration_status')]
class LegacyMigrationStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'migration_key', length: 255)]
    private string $key = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $lastId;

    public function __construct(
        string $key,
        int $lastId = 0,
    ) {
        $this->key = $key;
        $this->lastId = $lastId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getLastId(): int
    {
        return $this->lastId;
    }

    public function setLastId(int $lastId): void
    {
        $this->lastId = $lastId;
    }
}
