<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ZedExecutableTest extends TestCase
{
    public function testUsesTheCargoFromTheActiveRustupToolchain(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin', 'toolchain/bin');
        $rustup = $workspace->executable('bin/rustup', <<<'BASH'
            #!/bin/bash
            if [[ "$*" != "which cargo" ]]; then exit 1; fi
            printf '%s\n' "$RUSTUP_CARGO"
            BASH);
        $workspace->executable('bin/cargo', <<<'BASH'
            #!/bin/bash
            exit 99
            BASH);
        $cargo = $workspace->executable('toolchain/bin/cargo', <<<'BASH'
            #!/bin/bash
            printf '%s\n' "$*" >> "$CARGO_CALLS"
            BASH);
        $calls = $workspace->path('calls');

        try {
            $environment = getenv();
            $environment['PATH'] = \dirname($rustup);
            $environment['RUSTUP_CARGO'] = $cargo;
            $environment['CARGO_CALLS'] = $calls;
            $result = (new ExecutableRunner())->run(
                ['/bin/bash', Path::join($root, 'editor/zed/test')],
                $root,
                $environment,
            );

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame('', $result->stdout);
            self::assertSame('', $result->stderr);
            self::assertSame([
                'fmt --check',
                'test --locked',
                'check --locked --target wasm32-wasip2',
            ], file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            $workspace->cleanup();
        }
    }
}
