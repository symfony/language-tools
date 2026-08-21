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
        $first = new Project('/first', 'file:///first', '^8.0');
        $second = new Project('/second', 'file:///second', '^8.0');
        $registry->replace([$first, $second]);

        $third = new Project('/third', 'file:///third', '^8.0');
        $change = $registry->replace([$second, $third]);

        self::assertSame([$third], $change->added);
        self::assertSame([$first], $change->removed);
    }

    public function testRediscoveredRootsAreNeitherAddedNorRemoved(): void
    {
        $registry = new ProjectRegistry();
        $registry->replace([new Project('/workspace', 'file:///workspace', '^8.0')]);

        $rediscovered = new Project('/workspace', 'file:///workspace', '^8.1');
        $change = $registry->replace([$rediscovered]);

        self::assertSame([], $change->added);
        self::assertSame([], $change->removed);
        self::assertSame([$rediscovered], $registry->all());
    }

    public function testContainsMatchesByRootPath(): void
    {
        $registry = new ProjectRegistry();
        $registry->replace([new Project('/workspace', 'file:///workspace', '^8.0')]);

        self::assertTrue($registry->contains(new Project('/workspace', 'file:///workspace', '^8.1')));
        self::assertFalse($registry->contains(new Project('/other', 'file:///other', '^8.0')));
    }
}
