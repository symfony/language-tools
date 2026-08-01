<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Server\ServerVersion;

final class ServerVersionTest extends TestCase
{
    #[DataProvider('versionProvider')]
    public function testNormalizesVersion(string $version, string $expected): void
    {
        self::assertSame($expected, (new ServerVersion($version))->value());
    }

    /** @return iterable<string, array{string, string}> */
    public static function versionProvider(): iterable
    {
        yield 'development' => ['dev', 'dev'];
        yield 'release' => ['v1.2.3', '1.2.3'];
        yield 'prerelease' => ['1.2.3-RC1', '1.2.3-RC1'];
    }

    public function testRejectsInvalidVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServerVersion('dev-main');
    }
}
