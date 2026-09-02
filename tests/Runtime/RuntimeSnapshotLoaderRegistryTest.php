<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;

final class RuntimeSnapshotLoaderRegistryTest extends TestCase
{
    public function testExposesUniqueSectionNames(): void
    {
        $registry = new RuntimeSnapshotLoaderRegistry([
            new RecordingRuntimeSnapshotLoader('routes'),
            new RecordingRuntimeSnapshotLoader('container'),
            new RecordingRuntimeSnapshotLoader('routes'),
        ]);

        self::assertSame(['routes', 'container'], $registry->sections());
    }

    public function testPassesOnlyValidatedSectionPayloads(): void
    {
        $routes = new RecordingRuntimeSnapshotLoader('routes');
        $container = new RecordingRuntimeSnapshotLoader('container');
        $missing = new RecordingRuntimeSnapshotLoader('missing');
        $registry = new RuntimeSnapshotLoaderRegistry([$routes, $container, $missing]);
        $project = new Project('/workspace', 'file:///workspace');

        $registry->load($project, [
            'schemaVersion' => 1,
            'project' => ['environment' => 'dev'],
            'sections' => [
                'routes' => ['complete' => true, 'items' => []],
                'container' => 'invalid',
            ],
        ]);
        $registry->load($project, ['sections' => 'invalid']);
        $registry->load($project, []);

        self::assertSame([['complete' => true, 'items' => []]], $routes->loadedSections);
        self::assertSame([], $container->loadedSections);
        self::assertSame([], $missing->loadedSections);
    }
}

final class RecordingRuntimeSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    /** @var list<array<array-key, mixed>> */
    public array $loadedSections = [];

    public function __construct(private readonly string $section)
    {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function load(Project $project, array $section): void
    {
        $this->loadedSections[] = $section;
    }
}
