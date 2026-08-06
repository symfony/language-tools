<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\UriToPathConverter;

final class UriToPathConverterTest extends TestCase
{
    #[DataProvider('uriProvider')]
    public function testConvertsFileUris(string $uri, ?string $expected): void
    {
        self::assertSame($expected, (new UriToPathConverter())->convert($uri));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function uriProvider(): iterable
    {
        yield 'Unix path' => ['file:///workspace/my%20app/', '/workspace/my app'];
        yield 'root path' => ['file:///', '/'];
        yield 'dot segments' => ['file:///workspace/app/../src', '/workspace/src'];
        yield 'localhost' => ['file://localhost/workspace/app', '/workspace/app'];
        yield 'Windows path' => ['file:///C:/workspace/app', 'C:/workspace/app'];
        yield 'non-file URI' => ['untitled:buffer', null];
        yield 'remote host' => ['file://remote/workspace/app', null];
    }
}
