<?php

namespace Symfony\Lsp\Tests\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\DelimiterSegment;

final class DelimiterScannerTest extends TestCase
{
    /** @param list<string> $expected */
    #[DataProvider('splitProvider')]
    public function testSplitsOnTopLevelSeparators(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split($text),
        ));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function splitProvider(): iterable
    {
        yield 'empty text' => ['', ['']];
        yield 'single segment' => ['first', ['first']];
        yield 'two segments' => ['first, second', ['first', ' second']];
        yield 'trailing separator' => ['first,', ['first', '']];
        yield 'separator in a string' => ["'a,b', second", ["'a,b'", ' second']];
        yield 'escaped quote in a string' => ["'a\\',b', second", ["'a\\',b'", ' second']];
        yield 'separator in nested delimiters' => ['nested(1, [2, 3]), second', ['nested(1, [2, 3])', ' second']];
        yield 'unterminated delimiter' => ['nested(1, 2, second', ['nested(1, 2, second']];
        yield 'unterminated string' => ["'a, b", ["'a, b"]];
        yield 'unmatched closing delimiter' => ['first), second', ['first)', ' second']];
        yield 'multibyte segments' => ['café, thé', ['café', ' thé']];
    }

    public function testSplitReportsOffsetsFromTheBaseOffset(): void
    {
        $segments = DelimiterScanner::split("first, 'a,b', third", ',', 100);

        self::assertSame([100, 106, 113], array_map(static fn (DelimiterSegment $segment): int => $segment->offset, $segments));
    }

    public function testSplitsOnAnotherSeparator(): void
    {
        self::assertSame(['a', "'b;c'", ' d'], array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split("a;'b;c'; d", ';'),
        ));
    }

    /** @param list<string> $expected */
    #[DataProvider('twigInterpolationProvider')]
    public function testSplitsAroundTwigInterpolations(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split($text, twig: true),
        ));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function twigInterpolationProvider(): iterable
    {
        yield 'quotes and separators in an interpolation' => ['"a #{ "b, c" }", \'d\'', ['"a #{ "b, c" }"', " 'd'"]];
        yield 'nested interpolations' => ['"#{ "#{ \'x, y\' }, z" }", w', ['"#{ "#{ \'x, y\' }, z" }"', ' w']];
        yield 'hash in an interpolation' => ['"#{ {a: 1, b: 2}|length }", c', ['"#{ {a: 1, b: 2}|length }"', ' c']];
        yield 'escaped interpolation' => ['"\#{ ", "}", d', ['"\#{ "', ' "}"', ' d']];
        yield 'single-quoted strings do not interpolate' => ['\'#{\', "b"', ["'#{'", ' "b"']];
        yield 'unterminated interpolation' => ['"a #{ b, c', ['"a #{ b, c']];
    }

    public function testKeepsInterpolationMarkersInStringsOutsideTwig(): void
    {
        self::assertSame(['"a #{ "b', ' c" }"', " 'd'"], array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split('"a #{ "b, c" }", \'d\''),
        ));
    }

    public function testScansTwigInterpolationsForTerminatorsStatesAndMasks(): void
    {
        $text = '{{ "#{ "}}" }}" }} tail';
        self::assertSame(strrpos($text, '}}'), DelimiterScanner::terminator($text, 2, '}}', twig: true));
        self::assertSame(strpos($text, '}}', 3), DelimiterScanner::terminator($text, 2, '}}'));

        $text = "{{ \"a #{ path('";
        $state = DelimiterScanner::state($text, 2, twig: true);
        self::assertSame("'", $state->openString?->quote);
        self::assertSame(['#{', '('], array_map(static fn ($opening): string => $opening->delimiter, $state->openDelimiters));
        self::assertSame(strpos($text, '#'), $state->openDelimiters[0]->offset);

        $text = '{{ "a #{ b } c';
        $state = DelimiterScanner::state($text, 2, twig: true);
        self::assertNotNull($state->openString);
        self::assertSame('"', $state->openString->quote);
        self::assertSame(strpos($text, ' c'), $state->openString->contentOffset);
        self::assertSame([], $state->openDelimiters);

        self::assertSame('"  #{ " " }  " ~ \'   \'', DelimiterScanner::maskStrings('"a #{ "b" } c" ~ \'#{d\'', twig: true));
    }

    /** @param list<string> $expected */
    #[DataProvider('phpCommentProvider')]
    public function testSkipsPhpCommentsWhenAsked(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split($text, ',', 0, true),
        ));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function phpCommentProvider(): iterable
    {
        yield 'line comment' => ["first // one, two\n, second", ["first // one, two\n", ' second']];
        yield 'hash comment' => ["first # one, two\n, second", ["first # one, two\n", ' second']];
        yield 'attribute is not a comment' => ['#[Attr(1)] first, second', ['#[Attr(1)] first', ' second']];
        yield 'block comment' => ['first /* one, two */, second', ['first /* one, two */', ' second']];
        yield 'quote in a comment' => ["first /* it's here, really */, second", ["first /* it's here, really */", ' second']];
        yield 'unterminated block comment' => ['first /* one, two', ['first /* one, two']];
    }

    public function testKeepsPhpCommentsWhenNotAsked(): void
    {
        self::assertSame(['first // one', ' two', ' second'], array_map(
            static fn (DelimiterSegment $segment): string => $segment->text,
            DelimiterScanner::split('first // one, two, second'),
        ));
    }

    #[DataProvider('closeProvider')]
    public function testFindsTheClosingDelimiter(string $text, ?int $expected): void
    {
        self::assertSame($expected, DelimiterScanner::close($text, 0));
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function closeProvider(): iterable
    {
        yield 'nested parentheses' => ['(first(second)) trailing', 14];
        yield 'quoted delimiter' => ['("not )", second)', 16];
        yield 'escaped quote' => ['("not \")", second)', 18];
        yield 'unmatched' => ['(first(second)', null];
        yield 'brackets' => ["['value' => [1, 2]]", 18];
        yield 'braces' => ['{"a": {"b": 1}}', 14];
        yield 'mixed delimiters' => ['([{}])', 5];
        yield 'multibyte content' => ["('café', 'thé')", 16];
        yield 'not an opening delimiter' => ['first)', null];
        yield 'empty text' => ['', null];
    }

    public function testFindsTheClosingDelimiterFromAnInnerOffset(): void
    {
        $text = 'add(\'name\', Type::class, [\'label\' => \'Name (short)\'])';

        self::assertSame(\strlen($text) - 1, DelimiterScanner::close($text, 3));
        self::assertSame(\strlen($text) - 2, DelimiterScanner::close($text, (int) strpos($text, '[')));
    }

    public function testFindsATopLevelTerminator(): void
    {
        $text = "{{ 'a }}'|trans({'%name%': user.name}) }} tail";

        self::assertSame(strrpos($text, '}}'), DelimiterScanner::terminator($text, 2, '}}'));
    }

    public function testFindsATerminatorThatStartsInsideTheScannedRange(): void
    {
        $text = '{% set a = 1 %}';

        self::assertNull(DelimiterScanner::terminator($text, 2, '%}', 13));
        self::assertSame(13, DelimiterScanner::terminator($text, 2, '%}', 14));
        self::assertSame(13, DelimiterScanner::terminator($text, 2, '%}'));
    }

    public function testReportsTheStringLeftOpen(): void
    {
        $text = "{{ 'done' ~ \"open";
        $state = DelimiterScanner::state($text, 2);

        self::assertNotNull($state->openString);
        self::assertSame('"', $state->openString->quote);
        self::assertSame(strpos($text, 'open'), $state->openString->contentOffset);
        self::assertSame([], $state->openDelimiters);
    }

    public function testReportsNoOpenStringWhenEveryStringIsClosed(): void
    {
        $state = DelimiterScanner::state("{{ 'a \\' b' }}", 2);

        self::assertNull($state->openString);
    }

    public function testReportsTheDelimitersLeftOpen(): void
    {
        $text = "{{ path('app_home', {'id': ";
        $state = DelimiterScanner::state($text, 2);

        self::assertNull($state->openString);
        self::assertSame(['(', '{'], array_map(static fn ($opening): string => $opening->delimiter, $state->openDelimiters));
        self::assertSame([strpos($text, '('), strpos($text, '{', 2)], array_map(static fn ($opening): int => $opening->offset, $state->openDelimiters));
        self::assertSame(strpos($text, '{', 2), $state->innermostDelimiter()?->offset);
    }

    public function testScansOnlyTheRequestedRange(): void
    {
        $text = "markup 'quoted' {{ foo('bar";

        self::assertSame("'", DelimiterScanner::state($text)->openString?->quote);
        self::assertNull(DelimiterScanner::state($text, 0, (int) strpos($text, "'bar"))->openString);
        self::assertSame([], DelimiterScanner::state($text, 0, 16)->openDelimiters);
        self::assertSame(['{', '{', '('], array_map(
            static fn ($opening): string => $opening->delimiter,
            DelimiterScanner::state($text, 16, (int) strpos($text, "'bar"))->openDelimiters,
        ));
        self::assertSame('"', DelimiterScanner::state('a "b" c "d', 8)->openString?->quote);
    }

    public function testMasksStringContents(): void
    {
        self::assertSame("{{ '     '|trans }}", DelimiterScanner::maskStrings("{{ 'hello'|trans }}"));
        self::assertSame('{{ "   " ~ \'  \' }}', DelimiterScanner::maskStrings('{{ "a b" ~ \'cd\' }}'));
        self::assertSame("'    ' after", DelimiterScanner::maskStrings("'a\\'b' after"));
        self::assertSame("open '    ", DelimiterScanner::maskStrings("open 'tail"));
        self::assertSame("'  \n  ' out", DelimiterScanner::maskStrings("'ab\ncd' out"));
        self::assertSame("'     '", DelimiterScanner::maskStrings("'café'"));
    }
}
