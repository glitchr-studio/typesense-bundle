<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Integration;

use Composer\InstalledVersions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\TypesenseManager;
use Typesense\Bundle\Tests\Application\Kernel;
use Typesense\Bundle\Tests\Fixtures\Attribute\Book;
use Typesense\Bundle\Tests\Fixtures\Attribute\Tag;

/**
 * The bundle in a plain Symfony application (tests/Application: framework,
 * doctrine, this bundle, nothing else of the omni* family installed): the
 * collections of the attributes and of the configuration, typesense:create,
 * the Doctrine listener indexing on flush, a finder hydrating entities,
 * typesense:list.
 */
final class SymfonyApplicationTest extends KernelTestCase
{
    private static string $prefix;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        self::$prefix = 'app_' . bin2hex(random_bytes(4)) . '_';
        $_SERVER['TYPESENSE_PREFIX'] = $_ENV['TYPESENSE_PREFIX'] = self::$prefix;
        $_SERVER['TYPESENSE_URL'] ??= getenv('TYPESENSE_URL') ?: 'http://localhost:8108';
        $_SERVER['TYPESENSE_KEY'] ??= getenv('TYPESENSE_KEY') ?: 'xyz';
    }

    public function testNoPackageOfTheOmniFamilyIsInstalled(): void
    {
        if ('glitchr/typesense-bundle' !== InstalledVersions::getRootPackage()['name']) {
            $this->markTestSkipped('Installed in an application: its packages are its own.');
        }
        $this->assertSame([], array_values(preg_grep('~^(?:omni|glitchr/omni|omniscient/)~', InstalledVersions::getInstalledPackages())));
    }

    public function testAttributesAndConfigurationAreIndexedOnFlushAndFound(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get('typesense_manager');
        $this->assertInstanceOf(TypesenseManager::class, $manager);
        try {
            if (!$manager->getConnection()->getHealth()) {
                $this->markTestSkipped('Typesense is not healthy.');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('No reachable Typesense server: ' . $e->getMessage());
        }

        $this->assertSame(['tag', 'book'], array_keys($manager->getCollections()), 'the configuration\'s, then the attributes\'');
        $this->assertSame(self::$prefix . 'book', $manager->getCollection('book')->metadata()->getCollectionName());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $console = new ApplicationTester($application = new Application(self::$kernel));
        $application->setAutoExit(false);
        try {
            $this->assertSame(0, $console->run(['command' => 'typesense:create']), $console->getDisplay());

            $sf = new Tag('Science fiction', 'sf');
            $entityManager->persist(new Book('Dune', 3, $sf));
            $entityManager->persist(new Book('Dune Messiah', 2, $sf));
            $entityManager->persist(new Book('Emma', 1, new Tag('Novel', 'novel')));
            $entityManager->flush();
            $entityManager->clear();

            $response = self::getContainer()->get('typesense.finder.book')->query((new Query('title', 'dune'))->sortBy('rank:asc'));
            $this->assertSame(['Dune Messiah', 'Dune'], array_map(fn (Book $b) => $b->getTitle(), $response->getResults()));

            $tags = $manager->getFinder('tag')->raw(new Query('slug', '*'));
            $this->assertSame(2, $tags->getFound(), 'Tag, of the configuration, indexed too (its identifier read by Doctrine: Tag has no getId())');

            $this->assertSame(0, $console->run(['command' => 'typesense:list', '--collection' => 'book']));
            $this->assertStringContainsString('[' . self::$prefix . 'book]', $console->getDisplay());
        } finally {
            foreach ($manager->getCollections() as $collection) {
                $collection->delete();
            }
        }
    }
}
