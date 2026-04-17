<?php

namespace Disstudio\EntityImport\Exception;

class FactoryException extends \Exception
{
    public static function fromThrowable(\Throwable $throwable): static
    {
        return new static(
            sprintf('Factory exception: %s', $throwable->getMessage()),
            $throwable->getCode(),
            $throwable
        );
    }
}
