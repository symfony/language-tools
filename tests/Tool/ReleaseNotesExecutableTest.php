<?php

namespace Symfony\Lsp\Tests\Tool;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ReleaseNotesExecutableTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPrintsOnlyTheRequestedChangelogSection(): void
    {
        $changelog = Path::join($this->directory, 'CHANGELOG.md');
        file_put_contents($changelog, <<<'CHANGELOG'
            # Changelog

            ## Unreleased

            - Add upcoming behavior

            ## 0.2.0 (2026-08-24)

            - Add current behavior
            - Fix the release notes

            ## 0.1.0 (2026-08-23)

            - Add previous behavior
            CHANGELOG);

        $process = Process::start(
            [Path::join(\dirname(__DIR__, 2), 'tools/release-notes'), 'v0.2.0', $changelog],
            workingDirectory: $this->directory,
            options: ['bypass_shell' => true],
        );
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];
        $process->getStdin()->close();

        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("## 0.2.0 (2026-08-24)\n\n- Add current behavior\n- Fix the release notes\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }
}
