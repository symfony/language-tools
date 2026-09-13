<?php

namespace Symfony\Lsp\Tests\Parser\JavaScript;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokens;

final class JavaScriptTokenizerTest extends TestCase
{
    public function testSeparatesIdentifiersNumbersStringsAndPunctuators(): void
    {
        $tokens = $this->tokenize('const total = count + 2;');

        self::assertSame(
            [['const', 'Identifier'], ['total', 'Identifier'], ['=', 'Punctuator'], ['count', 'Identifier'], ['+', 'Punctuator'], ['2', 'Number'], [';', 'Punctuator']],
            $this->describe($tokens),
        );
    }

    public function testKeepsCommentsOutOfTheCodeTokens(): void
    {
        $tokens = $this->tokenize(<<<'JS'
            // a line comment
            const a = 1; /* a block comment */ const b = 2;
            JS);

        self::assertSame(['const', 'a', '=', '1', ';', 'const', 'b', '=', '2', ';'], array_column($this->describe($tokens), 0));
        self::assertSame(['// a line comment', '/* a block comment */'], array_map(static fn ($comment): string => $comment->value, $tokens->comments()));
    }

    public function testReportsStringContentsWithoutTheirQuotes(): void
    {
        $source = "const a = 'first';\nconst b = \"second\";";
        $tokens = $this->tokenize($source);
        $first = $tokens->at(3);
        $second = $tokens->at(8);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(['first', 'second'], [$first->value, $second->value]);
        self::assertSame(['first', 'second'], [substr($source, $first->offset, $first->length()), substr($source, $second->offset, $second->length())]);
    }

    public function testTokenizesTemplateInterpolationsWithoutTheirLiteralText(): void
    {
        $tokens = $this->tokenize('const a = `text ${value + nested(`inner ${deep}`)} tail`;');

        self::assertSame(
            ['const', 'a', '=', 'value', '+', 'nested', '(', 'deep', ')', ';'],
            array_column(array_values(array_filter($this->describe($tokens), static fn (array $token): bool => 'Template' !== $token[1])), 0),
        );
    }

    public function testKeepsBracesBalancedAroundTemplateInterpolations(): void
    {
        $tokens = $this->tokenize('const a = { key: `${ {nested: 1} }` };');
        $open = 3;

        self::assertTrue($tokens->isPunctuator($open, '{'));
        self::assertSame($tokens->count() - 2, $tokens->closingDelimiter($open));
    }

    /** @param list<string> $expected */
    #[DataProvider('regularExpressionProvider')]
    public function testDistinguishesRegularExpressionsFromDivision(string $source, array $expected): void
    {
        self::assertSame($expected, array_column($this->describe($this->tokenize($source)), 0));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function regularExpressionProvider(): iterable
    {
        yield 'after an assignment' => ['const a = /re\/gex/g;', ['const', 'a', '=', '/re\/gex/g', ';']];
        yield 'after a keyword' => ['return /re/.test(v);', ['return', '/re/', '.', 'test', '(', 'v', ')', ';']];
        yield 'after an identifier' => ['const a = b / c;', ['const', 'a', '=', 'b', '/', 'c', ';']];
        yield 'after a closing parenthesis' => ['const a = f(x) / c;', ['const', 'a', '=', 'f', '(', 'x', ')', '/', 'c', ';']];
        yield 'after a non-null assertion' => ['const a = v! / c;', ['const', 'a', '=', 'v', '!', '/', 'c', ';']];
        yield 'after a generic argument list' => ['const a = f<T> / c;', ['const', 'a', '=', 'f', '<', 'T', '>', '/', 'c', ';']];
        yield 'after an arrow' => ['const a = () => /re/;', ['const', 'a', '=', '(', ')', '=', '>', '/re/', ';']];
    }

    #[DataProvider('lineStartProvider')]
    public function testMarksTheFirstTokenOfEachLine(string $source, int $index, bool $expected): void
    {
        $token = $this->tokenize($source)->at($index);

        self::assertNotNull($token);
        self::assertSame($expected, $token->startsLine);
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function lineStartProvider(): iterable
    {
        yield 'indented line start' => ["a;\n    open", 2, true];
        yield 'mid line' => ["a;\n    open", 1, false];
        yield 'after a leading comment on the same line' => ["a;\n    /* c */ open", 2, false];
        yield 'after a comment on its own line' => ["a;\n    // c\n    open", 2, true];
    }

    public function testResolvesMemberAccessReceivers(): void
    {
        $tokens = $this->tokenize('app.register(); this.application.register(); a.b.register(); register();');

        self::assertSame('app', $tokens->receiver(2));
        self::assertSame('this.application', $tokens->receiver(10));
        self::assertNull($tokens->receiver(18));
        self::assertNull($tokens->receiver(24));
    }

    private function tokenize(string $source): JavaScriptTokens
    {
        return (new JavaScriptTokenizer())->tokenize($source);
    }

    /** @return list<array{string, string}> */
    private function describe(JavaScriptTokens $tokens): array
    {
        $described = [];
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            $token = $tokens->at($index);
            $described[] = null === $token ? ['', ''] : [$token->value, $token->kind->name];
        }

        return $described;
    }
}
