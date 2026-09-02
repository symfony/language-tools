<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ProjectRegistryTest extends TestCase
{
    public function testReportsAddedAndRemovedProjectsByRootPath(): void
    {
        $registry = new ProjectRegistry();
        $first = new Project('/first', 'file:///first');
        $second = new Project('/second', 'file:///second');
        $registry->replace([$first, $second]);

        $third = new Project('/third', 'file:///third');
        $change = $registry->replace([$second, $third]);

        self::assertSame([$third], $change->added);
        self::assertSame([$first], $change->removed);
    }

    public function testRediscoveredRootsAreNeitherAddedNorRemoved(): void
    {
        $registry = new ProjectRegistry();
        $registry->replace([new Project('/workspace', 'file:///workspace')]);

        $rediscovered = new Project('/workspace', 'file:///workspace');
        $change = $registry->replace([$rediscovered]);

        self::assertSame([], $change->added);
        self::assertSame([], $change->removed);
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
