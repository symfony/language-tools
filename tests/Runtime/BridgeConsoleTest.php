<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\ConsoleFixtureBuilder;

final class BridgeConsoleTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace('symfony-lsp-console-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testNormalizesDefinitionsAndApplicationDefaults(): void
    {
        $script = (new ConsoleFixtureBuilder($this->workspace))->writeDefinitionsScript();

        exec(\sprintf('%s %s', escapeshellarg(\PHP_BINARY), escapeshellarg($script)), $output, $exitCode);
        $realScript = realpath($script);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertTrue($result[1] ?? false);
        self::assertSame([], $result[2] ?? null);
        self::assertSame([[
            'class' => 'InvokableService',
            'file' => $realScript,
            'arguments' => ['command', 'report'],
            'options' => ['color', 'env', 'format', 'help', 'no-debug', 'verbose'],
            'complete' => false,
        ]], $result[0] ?? null);
    }
}
