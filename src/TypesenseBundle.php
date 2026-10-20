<?php

declare(strict_types=1);

namespace Typesense\Bundle;

use DoctrineExtensions\Query\Mysql\Field;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler\CollectionPass;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler\ConnectionPass;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler\FinderPass;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\Compiler\MetadataPass;
use Typesense\Bundle\Bridge\Symfony\DependencyInjection\TypesenseExtension;

/**
 * The bundle of a Symfony application: the services of the package's
 * framework-free root (connections, metadata, collections, finders, the
 * Doctrine indexer) from the `typesense` configuration and the attributes,
 * and the typesense:* commands. Its wiring lives in Bridge\Symfony; this
 * class stays here, at the root of the bundle's resources (translations).
 */
class TypesenseBundle extends Bundle
{
    public function boot(): void
    {
        // MySQL's FIELD() in DQL, as the bundle registered it before 2.0
        // (its finders no longer need it), where beberlei/doctrineextensions is.
        if ($this->container->has('doctrine.orm.entity_manager') && class_exists(Field::class)) {
            $this->container->get('doctrine.orm.entity_manager')->getConfiguration()
                ->addCustomNumericFunction('FIELD', Field::class);
        }
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new TypesenseExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ConnectionPass());
        $container->addCompilerPass(new MetadataPass());
        $container->addCompilerPass(new FinderPass());
        $container->addCompilerPass(new CollectionPass());
    }
}
