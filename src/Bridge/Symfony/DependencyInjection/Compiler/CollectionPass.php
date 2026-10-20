<?php

namespace Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\ORM\TypesenseManager;

class CollectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // always first check if the primary service is defined
        if (!$container->has(TypesenseManager::class)) {
            return;
        }

        $definition = $container->findDefinition(TypesenseManager::class);
        $taggedServices = $container->findTaggedServiceIds('typesense.collection');
        foreach ($taggedServices as $serviceName => $tags) {
            $reference = new Reference($serviceName);
            $definition->addMethodCall('addCollection', [$reference]);
        }
    }
}
