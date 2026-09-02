<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Environment\EnvironmentExpressionParser;

final class EnvironmentExpressionParserTest extends TestCase
{
    public function testParsesCompleteExpressionsWithAbsoluteVariableRanges(): void
    {
        $expression = (new EnvironmentExpressionParser())->parse('%env(json:key:feature:APP_CONFIG)%', 12);

        self::assertNotNull($expression);
        self::assertSame('APP_CONFIG', $expression->variableName);
        self::assertSame(['json', 'key', 'feature'], $expression->processorChain);
        self::assertSame([34, 44], [$expression->variableRange->startByte, $expression->variableRange->endByte]);
    }

    public function testFindsOnlyCompleteValidExpressions(): void
    {
        $expressions = (new EnvironmentExpressionParser())->parseAll('x %env(APP_URL)% %env(incomplete% %env(1INVALID)% %env(default::OPTIONAL)%');

        self::assertSame(['APP_URL', 'OPTIONAL'], array_map(static fn ($expression): string => $expression->variableName, $expressions));
        self::assertSame([['default', '']], array_map(static fn ($expression): array => $expression->processorChain, \array_slice($expressions, 1)));
    }
}
