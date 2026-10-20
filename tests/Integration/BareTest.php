<?php

declare(strict_types=1);

namespace Typesense\Bundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Typesense\Bundle\ORM\TypesenseManager;

/**
 * The package outside any framework: tests/bare.php, in a PHP process of
 * its own (this one has loaded the bundle's tests), builds everything by
 * hand on Doctrine alone and reports what PHP loaded on the way. Nothing
 * of a framework may be among it: no Symfony DependencyInjection, Config,
 * HttpKernel, HttpFoundation, Console, no bundle (Doctrine's own ORM and
 * Symfony's standalone components - Cache, PropertyAccess - are libraries).
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation|Console|Routing)|Symfony\\\\Bundle|Doctrine\\\\Bundle|Typesense\\\\Bundle\\\\(?:TypesenseBundle|Bridge))\\b~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|console|routing|[a-z-]*-bundle)|doctrine/doctrine-bundle)/|/src/(?:TypesenseBundle\.php|Bridge/)~';

    public function testWithAServerEntitiesAreIndexedSearchedAndRemovedWithNoFramework(): void
    {
        $report = self::bare();
        if (!$report['online']) {
            $this->markTestSkipped('No reachable Typesense server.');
        }

        $this->assertContains(TypesenseManager::class, $report['symbols']);
        $this->assertSame([], self::framework($report), 'no class nor file of a framework');
        $this->assertArrayNotHasKey('errors', $report);
        $this->assertSame(2, $report['found']);
        $this->assertSame(['Dune Messiah', 'Dune'], $report['hydrated'], 'entities in the order of the hits');
        $this->assertSame(2, $report['facets']['tags']['counts']['Science fiction']['count']);
        $this->assertSame(['Novel', 'Science fiction'], array_keys($report['facet']['tags']['counts']));
        $this->assertTrue($report['facet']['tags']['counts']['Novel']['checked']);
        $this->assertSame(1, $report['infix'], '"essi" in "Messiah", by infix');
        $this->assertSame(1, $report['after_remove']);
    }

    public function testWithoutAServerABookIsConvertedWithNoFramework(): void
    {
        $report = self::bare(['--offline']);

        $this->assertFalse($report['online']);
        $this->assertSame([], self::framework($report));
        $this->assertSame('Dune', $report['document']['title']);
        $this->assertSame(['sf'], $report['document']['tagSlugs']);
        $this->assertTrue($report['schema']['title']['infix']);
        $this->assertSame('int32', $report['schema']['rank']['type']);
    }

    /** The check is not blind: the same report names the framework once the bundle class is loaded. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        $out = shell_exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg('require getenv("TYPESENSE_BUNDLE_AUTOLOAD"); class_exists("Typesense\\\\Bundle\\\\TypesenseBundle"); echo json_encode(["symbols" => get_declared_classes(), "files" => get_included_files()]);') . ' 2>&1');
        $report = json_decode((string) $out, true);

        $this->assertIsArray($report, (string) $out);
        $this->assertNotSame([], self::framework($report));
    }

    private static function framework(array $report): array
    {
        return [...array_values(preg_grep(self::FRAMEWORK, $report['symbols'])), ...array_values(preg_grep(self::FRAMEWORK_FILES, $report['files']))];
    }

    private static function bare(array $arguments = []): array
    {
        $out = shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(\dirname(__DIR__) . '/bare.php') . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1');
        $report = json_decode((string) $out, true);
        self::assertIsArray($report, (string) $out);

        return $report;
    }

    public static function setUpBeforeClass(): void
    {
        putenv('TYPESENSE_BUNDLE_AUTOLOAD=' . \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php');
    }
}
