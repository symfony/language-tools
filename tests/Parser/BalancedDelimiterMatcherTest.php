<?php

namespace Symfony\Lsp\Tests\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;

final class BalancedDelimiterMatcherTest extends TestCase
{
    #[DataProvider('matchingProvider')]
    public function testFindsMatchingDelimiters(string $text, string $opening, string $closing, ?int $expected): void
    {
        self::assertSame($expected, (new BalancedDelimiterMatcher())->matching($text, 0, $opening, $closing));
    }

    /** @return iterable<string, array{string, string, string, ?int}> */
    public static function matchingProvider(): iterable
    {
        yield 'nested parentheses' => ['(first(second)) trailing', '(', ')', 14];
        yield 'quoted delimiter' => ['("not )", second)', '(', ')', 16];
        yield 'escaped quote' => ['("not \")", second)', '(', ')', 18];
        yield 'unmatched' => ['(first(second)', '(', ')', null];
        yield 'brackets' => ["['value' => [1, 2]]", '[', ']', 18];
    }
}
