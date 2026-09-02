<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\AbstractProjectIndexRegistry;
use Symfony\Lsp\Project\Project;

final class AbstractProjectIndexRegistryTest extends TestCase
{
    public function testKeepsOneIndexPerProjectRoot(): void
    {
        $registry = new TestProjectIndexRegistry();
        $first = new Project('/first', 'file:///first');
        $sameRoot = new Project('/first', 'file:///other');
        $second = new Project('/second', 'file:///second');

        self::assertSame($registry->forProject($first), $registry->forProject($sameRoot));
        self::assertNotSame($registry->forProject($first), $registry->forProject($second));
    }

    public function testRemovalReleasesTheIndexSoReAddingStartsFresh(): void
    {
        $registry = new TestProjectIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $index = $registry->forProject($project);

        $registry->removeProject($project);

        self::assertNotSame($index, $registry->forProject($project));
    }
}

/** @extends AbstractProjectIndexRegistry<TestProjectIndex> */
final class TestProjectIndexRegistry extends AbstractProjectIndexRegistry
{
    public function __construct()
    {
        parent::__construct(TestProjectIndex::class);
    }
}

final class TestProjectIndex
{
}
