<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StimulusBridgeTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-stimulus-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/example_controller.js');
        @rmdir($this->temporaryDirectory);
    }

    #[DataProvider('lazyCommentProvider')]
    public function testDetectsLazyControllersOnlyWhenTheCommentIsAttachedToTheClass(string $text, bool $expected): void
    {
        $sourcePath = $this->temporaryDirectory.'/example_controller.js';
        file_put_contents($sourcePath, $text);

        $script = \sprintf(
            'require %s; require %s; echo bridgeStimulusController(%s, %s, %s, null)[%s] ? "1" : "0";',
            var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true),
            var_export(\dirname(__DIR__, 2).'/resources/bridge/sections/stimulus.php', true),
            var_export($this->temporaryDirectory, true),
            var_export('example', true),
            var_export($sourcePath, true),
            var_export('lazy', true),
        );
        exec(\sprintf('%s -r %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($script)), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame([$expected ? '1' : '0'], $output);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function lazyCommentProvider(): iterable
    {
        yield 'double-quoted block comment' => ["/* stimulusFetch: \"lazy\" */\nexport default class extends Controller {}", true];
        yield 'line comment' => ["// stimulusFetch: 'lazy'\nexport default class extends Controller {}", true];
        yield 'detached comment' => ["/* stimulusFetch: 'lazy' */\nconst mode = 'eager';\nexport default class extends Controller {}", false];
    }
}
