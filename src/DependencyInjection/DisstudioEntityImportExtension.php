<?php

namespace Disstudio\EntityImport\DependencyInjection;

use Disstudio\EntityImport\Factory\FactoryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class DisstudioEntityImportExtension extends Extension
{
    public const FACTORY_SERVICE_TAG = 'entity_import.factory';

    /**
     * @inheritDoc
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('disstudio_entity_import.entity_map', $config['entity_map']);
        $container->setParameter('disstudio_entity_import.chunk_size', $config['chunk_size']);

        $container
            ->registerForAutoconfiguration(FactoryInterface::class)
            ->addTag(self::FACTORY_SERVICE_TAG);
    }
}

