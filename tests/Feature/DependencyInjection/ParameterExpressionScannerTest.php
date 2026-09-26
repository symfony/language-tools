<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\ParameterExpression;
use Symfony\Lsp\Feature\DependencyInjection\ParameterExpressionScanner;

final class ParameterExpressionScannerTest extends TestCase
{
    /** @param list<array{string, int}> $expected */
    #[DataProvider('scanProvider')]
    public function testScansParameterExpressions(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (ParameterExpression $parameter): array => [$parameter->name, $parameter->nameStartOffset],
            (new ParameterExpressionScanner())->scan($text),
        ));
    }

    /** @return iterable<string, array{string, list<array{string, int}>}> */
    public static function scanProvider(): iterable
    {
        yield 'single parameter' => ['%app.name%', [['app.name', 1]]];
        yield 'parameter in a sentence' => ['prefix %app.name% suffix', [['app.name', 8]]];
        yield 'several parameters' => ['%first%-%second%', [['first', 1], ['second', 9]]];
        yield 'escaped percent signs' => ['%%not.a.parameter%%', []];
        yield 'escaped then real' => ['%%escaped%% %real%', [['real', 13]]];
        yield 'environment placeholder' => ['%env(APP_URL)%', [['env(APP_URL)', 1]]];
        yield 'unterminated' => ['%app.name', []];
        yield 'whitespace is not a parameter name' => ['%app name%', []];
        yield 'empty delimiters' => ['%%', []];
        yield 'multibyte content' => ['%app.é% and %ünicode%', [['app.é', 1], ['ünicode', 14]]];
        yield 'percent in the middle' => ['50% off %app.name%', [['app.name', 9]]];
    }

    public function testShiftsOffsetsByTheBaseOffset(): void
    {
        $parameters = (new ParameterExpressionScanner())->scan('value: %app.name%', 100);

        self::assertCount(1, $parameters);
        self::assertSame('app.name', $parameters[0]->name);
        self::assertSame(108, $parameters[0]->nameStartOffset);
        self::assertSame(107, $parameters[0]->startOffset());
        self::assertSame('%app.name%', $parameters[0]->text());
    }
}
