<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteParameterKeyExtractor;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;

final class RouteParameterKeyExtractorTest extends TestCase
{
    /** @param list<string>|null $expected */
    #[DataProvider('parameterProvider')]
    public function testExtractsConservativeLiteralParameterKeys(?array $expected, string $afterRouteName): void
    {
        self::assertSame($expected, (new RouteParameterKeyExtractor(new BalancedDelimiterMatcher()))->extract($afterRouteName));
    }

    /** @return iterable<string, array{list<string>|null, string}> */
    public static function parameterProvider(): iterable
    {
        yield 'omitted parameter list' => [[], ')'];
        yield 'empty parameter list' => [[], ', [])'];
        yield 'dynamic parameter argument' => [null, ', $parameters)'];
        yield 'top-level unpacking' => [null, ', [...$parameters])'];
        yield 'top-level unpacking after a literal key' => [null, ", ['locale' => 'en', ...\$parameters])"];
        yield 'variable top-level key' => [null, ', [$key => 1])'];
        yield 'constant top-level key' => [null, ', [PARAMETER => 1])'];
        yield 'concatenated top-level key' => [null, ", ['i'.'d' => 1])"];
        yield 'interpolated top-level key' => [null, ', ["{$prefix}id" => 1])'];
        yield 'called top-level key' => [null, ', [parameter() => 1])'];
        yield 'nested dynamic key' => [['query'], ", ['query' => [\$key => 1]])"];
        yield 'unkeyed top-level value' => [[], ', [parameter()])'];
        yield 'nested argument unpacking' => [['id'], ", ['id' => sprintf(...\$parts)])"];
        yield 'nested array unpacking' => [['id', 'query'], ", ['id' => '1', 'query' => ['locale' => 'fr', ...\$parts]])"];
        yield 'balanced brackets inside strings' => [['id', 'slug'], ", ['id' => [']'], 'slug' => \"a\\\"b]\"])"];
        yield 'duplicate keys' => [['id'], ", ['id' => 1, 'id' => 2])"];
        yield 'unbalanced parameter array' => [null, ", ['id' => 1"];
        yield 'unexpected expression after parameter array' => [null, ", ['id' => 1] + [])"];

        foreach ([
            'escaped single quote' => "'it\\'s'",
            'escaped single backslash' => "'\\\\'",
            'escaped backslash' => '"\\\\"',
            'escaped quote' => '"\""',
            'escaped dollar' => '"\$"',
            'newline' => '"\n"',
            'carriage return' => '"\r"',
            'tab' => '"\t"',
            'vertical tab' => '"\v"',
            'escape' => '"\e"',
            'form feed' => '"\f"',
            'octal' => '"\101"',
            'two-digit hexadecimal' => '"\x64"',
            'one-digit hexadecimal' => '"\x4"',
            'ASCII Unicode' => '"i\u{64}"',
            'two-byte Unicode' => '"\u{80}"',
            'three-byte Unicode' => '"\u{800}"',
            'four-byte Unicode' => '"\u{1F600}"',
            'maximum Unicode codepoint' => '"\u{10FFFF}"',
            'unrecognized escape' => '"\d"',
            'hexadecimal without digits' => '"\xG"',
        ] as $name => $literal) {
            yield $name => [[self::evaluatePhpStringLiteral($literal)], ", [{$literal} => 1])"];
        }
    }

    private static function evaluatePhpStringLiteral(string $literal): string
    {
        $value = @eval('return '.$literal.';');
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('Expected PHP to evaluate %s as a string.', $literal));
        }

        return $value;
    }
}
