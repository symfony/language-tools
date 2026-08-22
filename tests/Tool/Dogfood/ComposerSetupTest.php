<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ComposerSetup;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
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

        (new ComposerSetup($processes))->setUp($this->directory);

        self::assertCount(1, $processes->calls);
        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress'], $processes->calls[0]['command']);
        self::assertSame($this->directory, $processes->calls[0]['directory']);
    }

    public function testSkipsScriptsWhenDisabled(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        (new ComposerSetup($processes, scripts: false))->setUp($this->directory);

        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'], $processes->calls[0]['command']);
    }

    public function testRejectsProjectsWithoutALockFile(): void
    {
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(0, '', '', false));

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('not reproducible');

        (new ComposerSetup($processes))->setUp($this->directory);
    }

    public function testReportsInstallationFailures(): void
    {
        file_put_contents(Path::join($this->directory, 'composer.lock'), '{}');
        $processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult(2, '', 'Your requirements could not be resolved.', false));

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('Your requirements could not be resolved.');

        (new ComposerSetup($processes))->setUp($this->directory);
    }
}
