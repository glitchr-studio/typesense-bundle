<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Application;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;
use Typesense\Bundle\TypesenseBundle;

/**
 * A Symfony application with nothing but FrameworkBundle, DoctrineBundle
 * and this bundle - none of the omni* packages: Book declares its
 * collection by attributes, Tag by the configuration's mappings, side by
 * side, on SQLite and the Typesense server of the test run.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new TypesenseBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/typesense-bundle-application/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/typesense-bundle-application/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $fixtures = \dirname(__DIR__) . '/Fixtures/Attribute';

        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]]);
        $container->services()->set('logger', NullLogger::class); // quiet: no output of the framework's own logger
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => ['mappings' => ['Fixtures' => ['type' => 'attribute', 'is_bundle' => false, 'dir' => $fixtures, 'prefix' => 'Typesense\Bundle\Tests\Fixtures\Attribute']]],
        ]);
        $container->extension('typesense', [
            'collection_prefix' => '%env(TYPESENSE_PREFIX)%',
            'attributes' => [$fixtures],
            'connections' => ['default' => ['url' => '%env(TYPESENSE_URL)%', 'secret' => '%env(TYPESENSE_KEY)%', 'options' => ['connection_timeout_seconds' => 2]]],
            'mappings' => ['tag' => ['class' => Tag::class, 'fields' => ['slug' => ['type' => 'string', 'facet' => true]]]],
        ]);
    }
}
