<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;

final class TwigDirectiveLocatorTest extends TestCase
{
    /** @param list<array{start: int, end: int}> $expected */
    #[DataProvider('rangeProvider')]
    public function testLocatesDirectiveRanges(string $text, array $expected): void
    {
        self::assertSame($expected, (new TwigDirectiveLocator())->ranges($text));
    }

    /** @return iterable<string, array{string, list<array{start: int, end: int}>}> */
    public static function rangeProvider(): iterable
    {
        yield 'markup only' => ['<p>markup</p>', []];
        yield 'expression' => ['a {{ name }} b', [['start' => 2, 'end' => 12]]];
        yield 'statement' => ['{% set a = 1 %}', [['start' => 0, 'end' => 15]]];
        yield 'two directives' => ['{{ a }}{% if b %}', [['start' => 0, 'end' => 7], ['start' => 7, 'end' => 17]]];
        yield 'terminator in a string' => ["{{ '}}' ~ a }}", [['start' => 0, 'end' => 14]]];
        yield 'escaped quote in a string' => ["{{ 'a\\'}}' }}", [['start' => 0, 'end' => 13]]];
        yield 'terminator in a hash' => ["{{ path('a', {'b': 1}) }}", [['start' => 0, 'end' => 25]]];
        yield 'closing brace next to the terminator' => ["{{ {'a': 1}}}", [['start' => 0, 'end' => 13]]];
        yield 'unterminated directive' => ['a {{ name', [['start' => 2, 'end' => 9]]];
        yield 'brace that opens nothing' => ['a { b }} c', []];
        yield 'unterminated string' => ["{{ 'name }}", [['start' => 0, 'end' => 11]]];
    }

    #[DataProvider('directiveStartProvider')]
    public function testReportsTheDirectiveOpenAtAnOffset(string $text, int $offset, ?int $expected): void
    {
        $locator = new TwigDirectiveLocator();

        self::assertSame($expected, $locator->directiveStart($text, $offset));
        self::assertSame(null !== $expected, $locator->insideDirective($text, $offset));
    }

    /** @return iterable<string, array{string, int, ?int}> */
    public static function directiveStartProvider(): iterable
    {
        yield 'inside an expression' => ['a {{ name }}', 6, 2];
        yield 'inside a statement' => ['{% if a %}', 5, 0];
        yield 'after the directive' => ['{{ name }} tail', 12, null];
        yield 'inside a string' => ["{{ 'na }} me' }}", 8, 0];
        yield 'in markup' => ['<p>markup</p>', 5, null];
        yield 'in a second directive' => ['{{ a }}{{ b', 10, 7];
        yield 'on the opening marker' => ['{{ name }}', 0, null];
    }

    public function testRecoversDirectivesLineByLine(): void
    {
        $text = "<p>{{ broken</p>\n<p>{{ name }}</p>\n{% if a %}";

        self::assertSame([
            ['start' => 3, 'end' => 16],
            ['start' => 20, 'end' => 30],
            ['start' => 35, 'end' => 45],
        ], iterator_to_array((new TwigDirectiveLocator())->recoveryRanges($text), false));
    }
}
