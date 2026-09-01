<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;

final class PhpLiteralArrayKeyParserTest extends TestCase
{
    public function testExtractsLiteralKeysWithExplicitUnpackingPolicy(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $items = <<<'PHP'
            'id' => sprintf(...$parts),
            'query' => ['locale' => 'fr', ...$parts],
            PHP;

        self::assertSame(['id', 'query'], $parser->parse($items, allowNestedUnpacking: true));
        self::assertNull($parser->parse($items, allowNestedUnpacking: false));
        self::assertNull($parser->parse("'id' => 1, ...\$parameters", allowNestedUnpacking: true));
    }

    public function testDecodesKeysAndRejectsDynamicKeys(): void
    {
        $parser = new PhpLiteralArrayKeyParser();

        self::assertSame(
            ["it's", "line\nkey"],
            $parser->parse(<<<'PHP'
                'it\'s' => 1,
                "line\nkey" => 2,
                PHP, allowNestedUnpacking: true),
        );
        self::assertNull($parser->parse('$key => 1', allowNestedUnpacking: true));
    }
}
