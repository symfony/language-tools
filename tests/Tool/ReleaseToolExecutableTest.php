<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;

final class ReleaseToolExecutableTest extends TestCase
{
    /** @param list<string> $arguments */
    #[DataProvider('executableProvider')]
    public function testLoadsWithoutComposerDevelopmentAutoloading(string $tool, array $arguments, string $error): void
    {
        $root = \dirname(__DIR__, 2);
        $result = (new ExecutableRunner())->run([\PHP_BINARY, '-n', $root.'/tools/'.$tool, ...$arguments], $root);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame($error."\n", $result->stderr);
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function executableProvider(): iterable
    {
        yield 'PHAR builder' => [
            'build-release-phar',
            ['tag', 'invalid'],
            'A release tag must use the vX.Y.Z or vX.Y.Z-PRERELEASE format.',
        ];
        yield 'package builder' => [
            'package-release',
            ['unsupported', 'tag', 'v1.2.3'],
            'Unsupported release platform "unsupported".',
        ];
    }
}
