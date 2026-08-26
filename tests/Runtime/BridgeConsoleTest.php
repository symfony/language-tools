<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BridgeConsoleTest extends TestCase
{
    public function testNormalizesDefinitionsAndApplicationDefaults(): void
    {
        $directory = sys_get_temp_dir().'/symfony-lsp-console-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $script = $directory.'/console.php';
        file_put_contents($script, str_replace('__BRIDGE__', var_export(\dirname(__DIR__, 2).'/resources/bridge/sections/console.php', true), <<<'PHP'
            <?php
            require __BRIDGE__;

            final class Definition
            {
                public function __construct(private array $arguments, private array $options) {}
                public function getArguments(): array { return array_fill_keys($this->arguments, new stdClass()); }
                public function getOptions(): array { return array_fill_keys($this->options, new stdClass()); }
            }

            final class InvokableService
            {
                public function __invoke(): void {}
            }

            final class WrappedCommand
            {
                public function __construct(private Definition $definition, private InvokableService $service) {}
                public function getDefinition(): Definition { return $this->definition; }
                public function getCode(): InvokableService { return $this->service; }
            }

            final class Application
            {
                public function getDefinition(): Definition { return new Definition(['command'], ['help', 'verbose', 'env', 'no-debug']); }
                public function all(): array
                {
                    return [
                        'first' => new WrappedCommand(new Definition(['report'], ['format']), new InvokableService()),
                        'second' => new WrappedCommand(new Definition(['report'], ['color']), new InvokableService()),
                    ];
                }
            }

            echo json_encode(symfonyLspBridgeConsoleDefinitions(new Application(), __DIR__), JSON_THROW_ON_ERROR);
            PHP));

        exec(\sprintf('%s %s', escapeshellarg(\PHP_BINARY), escapeshellarg($script)), $output, $exitCode);
        $realScript = realpath($script);
        (new Filesystem())->remove($directory);

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
