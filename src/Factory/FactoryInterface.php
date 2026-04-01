<?php

declare(strict_types=1);

namespace Disstudio\EntityImport\Factory;

/**
 * @template T of object
 */
interface FactoryInterface
{
    /**
     * @return T
     */
    public function createFromArray(array $data);
}
