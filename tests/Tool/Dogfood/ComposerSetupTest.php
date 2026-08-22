<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ComposerSetup;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\SetupException;

final class ComposerSetupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testInstallsThePinnedDependencySet(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        (new ComposerSetup($processes))->setUp($this->configuration(), $this->directory);

        self::assertCount(1, $processes->calls);
        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress'], $processes->calls[0]['command']);
        self::assertSame($this->directory, $processes->calls[0]['directory']);
    }

    public function testSkipsScriptsWhenDisabled(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        (new ComposerSetup($processes, scripts: false))->setUp($this->configuration(), $this->directory);

        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'], $processes->calls[0]['command']);
    }

    public function testCopiesThePinnedLockFileWhenTheProjectCommitsNone(): void
    {
        file_put_contents(Path::join($this->directory, 'pinned.lock'), '{"pinned": true}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        (new ComposerSetup($processes))->setUp($this->configuration(Path::join($this->directory, 'pinned.lock')), $this->directory);

        self::assertSame('{"pinned": true}', file_get_contents(Path::join($this->directory, 'composer.lock')));
    }

    public function testKeepsTheUpstreamLockFileWhenPresent(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{"upstream": true}');
        file_put_contents(Path::join($this->directory, 'pinned.lock'), '{"pinned": true}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        (new ComposerSetup($processes))->setUp($this->configuration(Path::join($this->directory, 'pinned.lock')), $this->directory);

        self::assertSame('{"upstream": true}', file_get_contents(Path::join($this->directory, 'composer.lock')));
    }

    public function testAllowsConfiguredPluginsAndRestoresTheManifest(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.json'), '{"name": "acme/app"}');
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(function (array $command): ProcessResult {
            if ('config' === $command[1]) {
                file_put_contents(Path::join($this->directory, 'composer.json'), '{"name": "acme/app", "config": {"allow-plugins": true}}');
            }

            return new ProcessResult(0, '', '', false);
        });

        (new ComposerSetup($processes))->setUp($this->configuration(allowPlugins: ['contao/manager-plugin']), $this->directory);

        self::assertSame(['composer', 'config', '--no-plugins', '--no-interaction', 'allow-plugins.contao/manager-plugin', 'true'], $processes->calls[0]['command']);
        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress'], $processes->calls[1]['command']);
        self::assertSame('{"name": "acme/app"}', file_get_contents(Path::join($this->directory, 'composer.json')));
    }

    public function testRejectsProjectsWithoutALockFile(): void
    {
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('not reproducible');

        (new ComposerSetup($processes))->setUp($this->configuration(), $this->directory);
    }

    public function testReportsInstallationFailures(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(2, '', 'Your requirements could not be resolved.', false));

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('Your requirements could not be resolved.');

        (new ComposerSetup($processes))->setUp($this->configuration(), $this->directory);
    }

    /**
     * @param list<string> $allowPlugins
     */
    private function configuration(?string $lockFile = null, array $allowPlugins = []): ProjectConfiguration
    {
        return new ProjectConfiguration('acme', 'https://github.com/acme/app.git', str_repeat('a', 40), null, 'dev', 'composer', false, 120, lockFile: $lockFile, allowPlugins: $allowPlugins);
    }
}
