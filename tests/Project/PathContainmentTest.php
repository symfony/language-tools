<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\PathContainment;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class PathContainmentTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testComparesPathsLexically(): void
    {
        self::assertTrue(PathContainment::contains('/workspace', '/workspace/src/../config/app.yaml'));
        self::assertTrue(PathContainment::contains('/workspace', '/workspace/'));
        self::assertFalse(PathContainment::contains('/workspace', '/workspace', false));
        self::assertFalse(PathContainment::contains('/workspace', '/workspace/../other'));
        self::assertFalse(PathContainment::contains('/workspace', '/workspace-other/file'));
    }

    public function testResolvesSymbolicLinksOfExistingPaths(): void
    {
        $this->workspace->mkdir('root', 'outside');
        $root = $this->workspace->path('root');
        $outside = $this->workspace->path('outside');
        $this->workspace->write('root/src/file.php', '');
        symlink($outside, $root.'/escape');

        self::assertTrue(PathContainment::resolvesInside($root, $root.'/src/file.php'));
        self::assertFalse(PathContainment::resolvesInside($root, $root.'/escape'));
        self::assertFalse(PathContainment::resolvesInside($root, $root, false));
    }

    public function testResolvesMissingPathsThroughTheirNearestExistingAncestor(): void
    {
        $this->workspace->mkdir('root', 'outside');
        $root = $this->workspace->path('root');
        $outside = $this->workspace->path('outside');
        symlink($outside, $root.'/escape');

        self::assertTrue(PathContainment::resolvesInside($root, $root.'/missing/project'));
        self::assertFalse(PathContainment::resolvesInside($root, $root.'/escape/missing/project'));
    }

    public function testFallsBackToLexicalContainmentForAMissingRoot(): void
    {
        $root = $this->workspace->path('missing');

        self::assertTrue(PathContainment::resolvesInside($root, $root.'/src/file.php'));
        self::assertFalse(PathContainment::resolvesInside($root, $this->workspace->path('other/file.php')));
    }
}
