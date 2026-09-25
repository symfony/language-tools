<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Runtime\SnapshotSection;

final class RuntimeSnapshotLoaderRegistryTest extends TestCase
{
    public function testExposesUniqueSectionNames(): void
    {
        $registry = self::registry(
            new RecordingRuntimeSnapshotLoader('routes'),
            new RecordingRuntimeSnapshotLoader('container'),
            new RecordingRuntimeSnapshotLoader('routes'),
        );

        self::assertSame(['routes', 'container'], $registry->sections());
    }

    public function testPassesOnlyValidatedSectionPayloads(): void
    {
        $routes = new RecordingRuntimeSnapshotLoader('routes');
        $container = new RecordingRuntimeSnapshotLoader('container');
        $missing = new RecordingRuntimeSnapshotLoader('missing');
        $registry = self::registry($routes, $container, $missing);
        $project = new Project('/workspace', 'file:///workspace');

        $registry->load($project, [
            'schemaVersion' => 1,
            'project' => ['environment' => 'dev'],
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'homepage']]],
                'container' => 'invalid',
            ],
        ]);
        $registry->load($project, ['sections' => 'invalid']);
        $registry->load($project, []);

        self::assertSame([[true, ['homepage']]], $routes->loadedSections);
        self::assertSame([], $container->loadedSections);
        self::assertSame([], $missing->loadedSections);
    }

    private static function registry(RuntimeSnapshotLoaderInterface ...$loaders): RuntimeSnapshotLoaderRegistry
    {
        return new RuntimeSnapshotLoaderRegistry($loaders, new ContainerPathMapper(new RuntimeConfiguration()));
    }
}

final class RecordingRuntimeSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    /** @var list<array{bool, list<string>}> */
    public array $loadedSections = [];

    public function __construct(private readonly string $section)
    {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $this->loadedSections[] = [$section->complete(), array_map(static fn (SnapshotSection $item): string => $item->string('name'), $section->items('items', 'name'))];
    }
}
