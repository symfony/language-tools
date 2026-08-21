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
        $first = new Project('/first', 'file:///first', '^8.0');
        $sameRoot = new Project('/first', 'file:///other', '^8.0');
        $second = new Project('/second', 'file:///second', '^8.0');

        self::assertSame($registry->forProject($first), $registry->forProject($sameRoot));
        self::assertNotSame($registry->forProject($first), $registry->forProject($second));
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
