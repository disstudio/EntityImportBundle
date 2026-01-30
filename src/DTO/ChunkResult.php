<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\DTO;

final readonly class ChunkResult
{
    public function __construct(
        private int $rowsMigrated,
        private int $rowsSkipped,
    ) {
    }

    public function getRowsMigrated(): int
    {
        return $this->rowsMigrated;
    }

    public function getRowsSkipped(): int
    {
        return $this->rowsSkipped;
    }

    public function getRowsTotal(): int
    {
        return $this->rowsMigrated + $this->rowsSkipped;
    }
}
