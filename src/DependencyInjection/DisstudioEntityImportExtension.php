<?php

namespace Disstudio\EntityImport\DependencyInjection;

use Disstudio\EntityImport\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class DisstudioEntityImportExtension extends Extension
{
    public const FACTORY_SERVICE_TAG = 'entity_import.factory';

    /**
     * @inheritDoc
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $this->processConfiguration(new Configuration(), $configs);

        $container
            ->registerForAutoconfiguration(FactoryInterface::class)
            ->addTag(self::FACTORY_SERVICE_TAG);
    }
}
