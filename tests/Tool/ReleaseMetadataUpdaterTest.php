<?php

namespace Symfony\Lsp\Tests\Tool;

require_once \dirname(__DIR__, 2).'/tools/ReleaseMetadataUpdater.php';

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\ReleaseMetadataUpdater;

final class ReleaseMetadataUpdaterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 2).'/var/tests/release-metadata-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/docs/editors', 0777, true);
        mkdir($this->directory.'/lua/symfony_lsp', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testPreparesReleaseMetadata(): void
    {
        $this->writeFixture("- Add release automation\n");

        (new ReleaseMetadataUpdater())->prepare($this->directory, '0.3.0', '0.3.1', '2026-08-05');

        self::assertSame(
            "# Changelog\n\n## 0.3.1 (2026-08-05)\n\n- Add release automation\n\n## 0.3.0 (2026-08-04)\n",
            file_get_contents($this->directory.'/CHANGELOG.md'),
        );
        self::assertSame("Install 0.3.1\n", file_get_contents($this->directory.'/docs/index.rst'));
        self::assertSame("Build 0.3.1\n", file_get_contents($this->directory.'/docs/editors/vscode.rst'));
        self::assertSame("Configure 0.3.1\n", file_get_contents($this->directory.'/docs/editors/neovim.rst'));
        self::assertSame("return '0.3.1'\n", file_get_contents($this->directory.'/lua/symfony_lsp/version.lua'));
    }

    public function testDoesNotPartiallyUpdateInvalidMetadata(): void
    {
        $this->writeFixture("- Add release automation\n");
        file_put_contents($this->directory.'/docs/editors/vscode.rst', "Build dev\n");
        $changelog = file_get_contents($this->directory.'/CHANGELOG.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains no 0.3.0 installation example');

        try {
            (new ReleaseMetadataUpdater())->prepare($this->directory, '0.3.0', '0.3.1', '2026-08-05');
        } finally {
            self::assertSame($changelog, file_get_contents($this->directory.'/CHANGELOG.md'));
            self::assertSame("Install 0.3.0\n", file_get_contents($this->directory.'/docs/index.rst'));
            self::assertSame("return '0.3.0'\n", file_get_contents($this->directory.'/lua/symfony_lsp/version.lua'));
        }
    }

    public function testStartsNextDevelopmentCycleOnce(): void
    {
        file_put_contents(
            $this->directory.'/CHANGELOG.md',
            "# Changelog\n\n## 0.3.1 (2026-08-05)\n\n- Add release automation\n",
        );
        $updater = new ReleaseMetadataUpdater();

        self::assertTrue($updater->startNextDevelopment($this->directory));
        self::assertFalse($updater->startNextDevelopment($this->directory));
        $changelog = file_get_contents($this->directory.'/CHANGELOG.md');
        self::assertIsString($changelog);
        self::assertStringStartsWith("# Changelog\n\n## Unreleased\n\n## 0.3.1", $changelog);
    }

    private function writeFixture(string $entries): void
    {
        file_put_contents(
            $this->directory.'/CHANGELOG.md',
            "# Changelog\n\n## Unreleased\n\n{$entries}\n## 0.3.0 (2026-08-04)\n",
        );
        file_put_contents($this->directory.'/docs/index.rst', "Install 0.3.0\n");
        file_put_contents($this->directory.'/docs/editors/vscode.rst', "Build 0.3.0\n");
        file_put_contents($this->directory.'/docs/editors/neovim.rst', "Configure 0.3.0\n");
        file_put_contents($this->directory.'/lua/symfony_lsp/version.lua', "return '0.3.0'\n");
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
