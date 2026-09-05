<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;

final class PhpLiteralArrayKeyParserTest extends TestCase
{
    public function testExtractsLiteralKeysWithExplicitUnpackingPolicy(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $items = <<<'PHP'
            'id' => sprintf(...$parts),
            'query' => ['locale' => 'fr', ...$parts],
            PHP;

        self::assertSame(['id', 'query'], $this->values($parser->parse($items, allowNestedUnpacking: true)));
        self::assertNull($parser->parse($items, allowNestedUnpacking: false));
        self::assertNull($parser->parse("'id' => 1, ...\$parameters", allowNestedUnpacking: true));
        self::assertSame(
            ['id', 'after'],
            $this->values($parser->parse("'id' => 1, \$dynamic => 2, ...\$parameters, 'after' => 3", allowNestedUnpacking: true, collectPartialLiteralKeys: true)),
        );
    }

    public function testDecodesKeysAndPreservesRawSourceOffsets(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $prefix = 'before ';
        $items = <<<'PHP'
            'it\'s' => [']', ',' => 'nested'],
            "line\nkey" => 'value, with => delimiters',
            PHP;
        $source = $prefix.$items;
        $keys = $parser->parse($items, allowNestedUnpacking: true, sourceOffset: \strlen($prefix));

        self::assertSame(["it's", "line\nkey"], $this->values($keys));
        self::assertSame(["it\\'s", 'line\\nkey'], array_map(
            static fn (PhpStringLiteral $key): string => substr($source, $key->startOffset, $key->endOffset - $key->startOffset),
            $keys ?? [],
        ));
    }

    public function testRejectsDynamicKeysUnlessPartialCollectionIsRequested(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $items = <<<'PHP'
            'before' => 1,
            key('with, delimiter') => 2,
            "{$prefix}dynamic" => 3,
            'after' => 4,
            PHP;

        self::assertNull($parser->parse($items, allowNestedUnpacking: true));
        self::assertSame(['before', 'after'], $this->values($parser->parse($items, allowNestedUnpacking: true, collectPartialLiteralKeys: true)));
    }

    public function testParsesArrayExpressionsWithSyntaxPolicyAndOffsets(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $prefix = 'before ';
        $expression = "  ArRaY ( 'legacy' => 1, \$dynamic => 2, ...\$values, 'after' => 3 )  ";
        $source = $prefix.$expression;

        self::assertSame(['short'], $this->values($parser->parseExpression("['short' => 1]", allowNestedUnpacking: true)));
        self::assertNull($parser->parseExpression("['incomplete' => 1", allowNestedUnpacking: true));
        $keys = $parser->parseExpression(
            $expression,
            allowNestedUnpacking: true,
            collectPartialLiteralKeys: true,
            sourceOffset: \strlen($prefix),
        );

        self::assertSame(['legacy', 'after'], $this->values($keys));
        self::assertSame(['legacy', 'after'], array_map(
            static fn (PhpStringLiteral $key): string => substr($source, $key->startOffset, $key->endOffset - $key->startOffset),
            $keys ?? [],
        ));
    }

    public function testParsesPhpArgumentWithoutChangingConservativeEntryHandling(): void
    {
        $parser = new PhpLiteralArrayKeyParser();
        $prefix = 'before ';
        $expression = " [ 'before' => 1, \$dynamic => 2, ...\$values, 'after' => 3 ] ";
        $argument = new PhpArgument(
            name: null,
            nameStartOffset: null,
            nameEndOffset: null,
            stringLiteral: null,
            completeLiteral: null,
            callable: null,
            expression: $expression,
            startOffset: \strlen($prefix),
            endOffset: \strlen($prefix.$expression),
            expressionStartOffset: \strlen($prefix),
            expressionEndOffset: \strlen($prefix.$expression),
            unpacked: false,
        );

        self::assertNull($parser->parseArgument($argument, allowNestedUnpacking: true));
        self::assertSame(
            ['before', 'after'],
            $this->values($parser->parseArgument($argument, allowNestedUnpacking: true, collectPartialLiteralKeys: true)),
        );
    }

    /**
     * @param list<PhpStringLiteral>|null $keys
     *
     * @return list<string>|null
     */
    private function values(?array $keys): ?array
    {
        return null === $keys ? null : array_map(static fn (PhpStringLiteral $key): string => $key->value, $keys);
    }
}
