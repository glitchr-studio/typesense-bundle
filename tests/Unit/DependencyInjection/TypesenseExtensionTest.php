<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Typesense\Bundle\DependencyInjection\TypesenseExtension;
use Typesense\Bundle\ORM\TypesenseManager;

class TypesenseExtensionTest extends TestCase
{
    private function makeContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // load() replaces TypesenseManager's argument 0, so the definition
        // must already exist — normally done by services.php.
        $container->register(TypesenseManager::class)->setArguments([null]);

        return $container;
    }

    /**
     * Regression: load() used to read $config['default_connection'] to
     * seed TypesenseManager's default connection name, but $config was
     * never assigned anywhere in the method (the processed configuration
     * is $typesense) — the undefined-variable warning was swallowed by ??,
     * so this silently ALWAYS resolved to the literal 'default', no matter
     * what default_connection was actually configured. m.beta's own
     * config/packages/typesense.yaml happens to already use
     * default_connection: default, which is exactly why this went
     * unnoticed — any app naming its default connection something else
     * would silently get the wrong one wired into TypesenseManager.
     */
    public function testDefaultConnectionNameIsActuallyHonored(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([[
            'default_connection' => 'primary',
            'connections' => [
                'primary' => ['secret' => 'a'],
            ],
        ]], $container);

        $definition = $container->getDefinition(TypesenseManager::class);
        $this->assertSame('primary', $definition->getArgument(0));
    }

    public function testDefaultConnectionFallsBackToDefaultWhenNotConfigured(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([], $container);

        $definition = $container->getDefinition(TypesenseManager::class);
        $this->assertSame('default', $definition->getArgument(0));
    }

    public function testEachConfiguredConnectionGetsItsOwnServiceDefinition(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([[
            'connections' => [
                'primary' => ['secret' => 'a'],
                'secondary' => ['secret' => 'b'],
            ],
        ]], $container);

        $this->assertTrue($container->hasDefinition('typesense.connection.primary'));
        $this->assertTrue($container->hasDefinition('typesense.connection.secondary'));
    }

    public function testAMappingNameContainingADoubleUnderscoreIsRejected(): void
    {
        $container = $this->makeContainer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Collection name cannot contains `__`');

        (new TypesenseExtension())->load([[
            'mappings' => [
                'article__blog' => ['class' => 'App\\Entity\\Article'],
            ],
        ]], $container);
    }

    /**
     * The prefix is the metadata's business only (it is what names the
     * collection on the server): the service ids an application autowires
     * — typesense.finder.article and friends — stay on the mapping key.
     */
    public function testCollectionPrefixReachesTheMetadataAndLeavesServiceIdsAlone(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([[
            'collection_prefix' => 'test_',
            'connections' => [
                'default' => ['secret' => 'a'],
            ],
            'mappings' => [
                'article' => ['class' => 'App\\Entity\\Article'],
            ],
        ]], $container);

        $metadata = $container->getDefinition('typesense.metadata.article');
        $this->assertSame('article', $metadata->getArgument(0));
        $this->assertSame('test_', $metadata->getArgument(3));
        $this->assertSame('test_', $container->getParameter('typesense.collection_prefix'));

        $this->assertTrue($container->hasDefinition('typesense.collection.article'));
        $this->assertTrue($container->hasDefinition('typesense.finder.article'));
        $this->assertFalse($container->hasDefinition('typesense.finder.test_article'));
    }

    public function testCollectionPrefixIsEmptyWhenNotConfigured(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([[
            'mappings' => [
                'article' => ['class' => 'App\\Entity\\Article'],
            ],
        ]], $container);

        $this->assertSame('', $container->getDefinition('typesense.metadata.article')->getArgument(3));
    }

    /**
     * `collection_prefix: ~` in YAML is null, and TypesenseMetadata takes a
     * string: null means "no prefix", not a TypeError at boot.
     */
    public function testANullCollectionPrefixMeansNoPrefix(): void
    {
        $container = $this->makeContainer();

        (new TypesenseExtension())->load([[
            'collection_prefix' => null,
            'mappings' => [
                'article' => ['class' => 'App\\Entity\\Article'],
            ],
        ]], $container);

        $this->assertSame('', $container->getDefinition('typesense.metadata.article')->getArgument(3));
    }

    public function testSetConfigurationFlattensNestedArraysIntoDotNotationParameters(): void
    {
        $container = new ContainerBuilder();
        (new TypesenseExtension())->setConfiguration($container, [
            'default_connection' => 'default',
            'connections' => [
                'default' => ['host' => 'localhost'],
            ],
        ], 'typesense');

        $this->assertSame('default', $container->getParameter('typesense.default_connection'));
        $this->assertSame('localhost', $container->getParameter('typesense.connections.default.host'));
    }
}
