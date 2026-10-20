<?php

namespace Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\ORM\TypesenseManager;

class MetadataPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TypesenseManager::class)) {
            return;
        }

        $definition = $container->findDefinition(TypesenseManager::class);
        $taggedServices = $container->findTaggedServiceIds('typesense.metadata');
        foreach ($taggedServices as $className => $tags) {
            $reference = new Reference($className);
            $definition->addMethodCall('addMetadata', [$reference]);
        }
    }
}
