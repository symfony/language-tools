<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\ReleaseMetadataUpdater;

final class ReleaseMetadataUpdaterTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-release-metadata-');
        $this->workspace->mkdir('docs');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testPreparesReleaseMetadata(): void
    {
        $this->writeFixture("- Add release automation\n");

        (new ReleaseMetadataUpdater())->prepare($this->workspace->rootPath, '0.3.1', '2026-08-05');

        self::assertSame(
            "# Changelog\n\n## 0.3.1 (2026-08-05)\n\n- Add release automation\n\n## 0.3.0 (2026-08-04)\n",
            file_get_contents($this->workspace->path('CHANGELOG.md')),
        );
        self::assertSame("Install vX.Y.Z\n", file_get_contents($this->workspace->path('docs/index.rst')));
    }

    public function testStartsNextDevelopmentCycleOnce(): void
    {
        $this->workspace->write(
            'CHANGELOG.md',
            "# Changelog\n\n## 0.3.1 (2026-08-05)\n\n- Add release automation\n",
        );
        $updater = new ReleaseMetadataUpdater();

        self::assertTrue($updater->startNextDevelopment($this->workspace->rootPath));
        self::assertFalse($updater->startNextDevelopment($this->workspace->rootPath));
        $changelog = file_get_contents($this->workspace->path('CHANGELOG.md'));
        self::assertIsString($changelog);
        self::assertStringStartsWith("# Changelog\n\n## Unreleased\n\n## 0.3.1", $changelog);
    }

    private function writeFixture(string $entries): void
    {
        $this->workspace->write(
            'CHANGELOG.md',
            "# Changelog\n\n## Unreleased\n\n{$entries}\n## 0.3.0 (2026-08-04)\n",
        );
        $this->workspace->write('docs/index.rst', "Install vX.Y.Z\n");
    }
}
