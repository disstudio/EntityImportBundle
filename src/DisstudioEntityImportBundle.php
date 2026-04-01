<?php

namespace Disstudio\EntityImport;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DisstudioEntityImportBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
