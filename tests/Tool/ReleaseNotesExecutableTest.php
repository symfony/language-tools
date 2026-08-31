<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ReleaseNotesExecutableTest extends TestCase
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

    public function testPrintsOnlyTheRequestedChangelogSection(): void
    {
        $changelog = $this->workspace->write('CHANGELOG.md', <<<'CHANGELOG'
            # Changelog

            ## Unreleased

            - Add upcoming behavior

            ## 0.2.0 (2026-08-24)

            - Add current behavior
            - Fix the release notes

            ## 0.1.0 (2026-08-23)

            - Add previous behavior
            CHANGELOG);

        $result = (new ExecutableRunner())->run(
            [Path::join(\dirname(__DIR__, 2), 'tools/release-notes'), 'v0.2.0', $changelog],
            $this->workspace->rootPath,
        );

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame("## 0.2.0 (2026-08-24)\n\n- Add current behavior\n- Fix the release notes\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }
}
