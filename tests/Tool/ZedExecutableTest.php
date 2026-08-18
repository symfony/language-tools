<?php

namespace Symfony\Lsp\Tests\Tool;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ZedExecutableTest extends TestCase
{
    public function testUsesTheCargoFromTheActiveRustupToolchain(): void
    {
        $root = \dirname(__DIR__, 2);
        $directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-'.bin2hex(random_bytes(8)));
        $bin = Path::join($directory, 'bin');
        $toolchainBin = Path::join($directory, 'toolchain/bin');
        $rustup = Path::join($bin, 'rustup');
        $cargo = Path::join($toolchainBin, 'cargo');
        $calls = Path::join($directory, 'calls');
        (new Filesystem())->mkdir([$bin, $toolchainBin]);
        file_put_contents($rustup, <<<'BASH'
            #!/bin/bash
            if [[ "$*" != "which cargo" ]]; then exit 1; fi
            printf '%s\n' "$RUSTUP_CARGO"
            BASH);
        file_put_contents(Path::join($bin, 'cargo'), <<<'BASH'
            #!/bin/bash
            exit 99
            BASH);
        file_put_contents($cargo, <<<'BASH'
            #!/bin/bash
            printf '%s\n' "$*" >> "$CARGO_CALLS"
            BASH);
        chmod($rustup, 0755);
        chmod(Path::join($bin, 'cargo'), 0755);
        chmod($cargo, 0755);

        try {
            $environment = getenv();
            $environment['PATH'] = $bin;
            $environment['RUSTUP_CARGO'] = $cargo;
            $environment['CARGO_CALLS'] = $calls;
            $process = Process::start(
                ['/bin/bash', Path::join($root, 'editor/zed/test')],
                workingDirectory: $root,
                environment: $environment,
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
            self::assertSame('', $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertSame([
                'fmt --check',
                'test --locked',
                'check --locked --target wasm32-wasip2',
            ], file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}
