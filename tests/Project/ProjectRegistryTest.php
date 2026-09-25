<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ProjectRegistryTest extends TestCase
{
    public function testReportsRemovedProjectsByRootPath(): void
    {
        $registry = new ProjectRegistry();
        $first = new Project('/first', 'file:///first');
        $second = new Project('/second', 'file:///second');
        $registry->replace([$first, $second]);

        $third = new Project('/third', 'file:///third');
        self::assertSame([$first], $registry->replace([$second, $third]));
    }

    public function testRediscoveredRootsAreNotRemoved(): void
    {
        $registry = new ProjectRegistry();
        $registry->replace([new Project('/workspace', 'file:///workspace')]);

        $rediscovered = new Project('/workspace', 'file:///workspace');
        self::assertSame([], $registry->replace([$rediscovered]));
        self::assertSame([$rediscovered], $registry->all());
    }

    public function testContainsMatchesByRootPath(): void
    {
        $registry = new ProjectRegistry();
        $registry->replace([new Project('/workspace', 'file:///workspace')]);

        self::assertTrue($registry->contains(new Project('/workspace', 'file:///workspace')));
        self::assertFalse($registry->contains(new Project('/other', 'file:///other')));
    }
}
