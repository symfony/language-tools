<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;

final class TwigArgumentParserTest extends TestCase
{
    public function testParsesNamedAndPositionalArguments(): void
    {
        $text = " first, second: nested(1, [2, 3]), third = 'a,b', item => item.value";
        $arguments = (new TwigArgumentParser())->parse($text, 100);

        self::assertSame(
            ['first', 'second: nested(1, [2, 3])', "third = 'a,b'", 'item => item.value'],
            array_map(static fn ($argument): string => trim($argument->text), $arguments),
        );
        self::assertSame([null, 'second', 'third', null], array_map(static fn ($argument): ?string => $argument->name, $arguments));
        self::assertSame(100 + strpos($text, 'first'), $arguments[0]->valueOffset);
        self::assertSame(100 + strpos($text, 'second'), $arguments[1]->nameOffset);
        self::assertSame(100 + strpos($text, 'nested'), $arguments[1]->valueOffset);
        self::assertSame(100 + strpos($text, 'third'), $arguments[2]->nameOffset);
        self::assertSame(100 + strpos($text, "'a,b'"), $arguments[2]->valueOffset);
        self::assertSame(100 + strpos($text, 'item =>'), $arguments[3]->valueOffset);
    }
}
