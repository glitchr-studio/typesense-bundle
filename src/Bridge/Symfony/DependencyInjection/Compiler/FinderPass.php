<?php

namespace Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\ORM\TypesenseManager;

class FinderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TypesenseManager::class)) {
            return;
        }

        $definition = $container->findDefinition(TypesenseManager::class);
        $taggedServices = $container->findTaggedServiceIds('typesense.finder');
        foreach ($taggedServices as $serviceName => $tags) {
            $reference = new Reference($serviceName);
            $definition->addMethodCall('addFinder', [$reference]);
        }
    }
}
