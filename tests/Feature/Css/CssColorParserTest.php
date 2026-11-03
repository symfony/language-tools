<?php

namespace Symfony\Lsp\Tests\Feature\Css;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Css\CssColorNotation;
use Symfony\Lsp\Feature\Css\CssColorParser;

final class CssColorParserTest extends TestCase
{
    #[DataProvider('colorProvider')]
    public function testParsesColorsToSrgb(string $value, string $hex, float $alpha, CssColorNotation $notation): void
    {
        $color = (new CssColorParser())->parse($value);

        self::assertNotNull($color);
        self::assertSame($notation, $color->notation);
        self::assertSame($hex, \sprintf('#%02x%02x%02x', round($color->color->red * 255), round($color->color->green * 255), round($color->color->blue * 255)));
        self::assertEqualsWithDelta($alpha, $color->color->alpha, 0.001);
    }

    /** @return iterable<string, array{string, string, float, CssColorNotation}> */
    public static function colorProvider(): iterable
    {
        yield 'six digit hex' => ['#3b82f6', '#3b82f6', 1.0, CssColorNotation::Hex];
        yield 'three digit hex' => ['#FA0', '#ffaa00', 1.0, CssColorNotation::Hex];
        yield 'hex with alpha' => ['#3b82f680', '#3b82f6', 0.502, CssColorNotation::Hex];
        yield 'short hex with alpha' => ['#0008', '#000000', 0.533, CssColorNotation::Hex];
        yield 'named' => ['RebeccaPurple', '#663399', 1.0, CssColorNotation::Named];
        yield 'transparent' => ['transparent', '#000000', 0.0, CssColorNotation::Named];
        yield 'modern rgb' => ['rgb(59 130 246 / 50%)', '#3b82f6', 0.5, CssColorNotation::Rgb];
        yield 'legacy rgba' => ['rgba(255, 0, 0, 0.25)', '#ff0000', 0.25, CssColorNotation::Rgb];
        yield 'rgb percentages' => ['rgb(100% 50% 0%)', '#ff8000', 1.0, CssColorNotation::Rgb];
        yield 'rgb none' => ['rgb(none 255 none)', '#00ff00', 1.0, CssColorNotation::Rgb];
        yield 'hsl' => ['hsl(217.2 91.2% 59.8%)', '#3b82f6', 1.0, CssColorNotation::Hsl];
        yield 'legacy hsla with turn' => ['hsla(0.5turn, 100%, 50%, .5)', '#00ffff', 0.5, CssColorNotation::Hsl];
        yield 'hwb' => ['hwb(120 10% 20%)', '#1acc1a', 1.0, CssColorNotation::Hwb];
        yield 'hwb gray' => ['hwb(0 60% 60%)', '#808080', 1.0, CssColorNotation::Hwb];
        yield 'lab' => ['lab(54.29% 80.8 69.89)', '#ff0000', 1.0, CssColorNotation::Lab];
        yield 'lch' => ['lch(54.29 106.84 40.85deg)', '#ff0000', 1.0, CssColorNotation::Lch];
        yield 'oklab' => ['oklab(0.628 0.225 0.126)', '#ff0000', 1.0, CssColorNotation::Oklab];
        yield 'oklch' => ['oklch(62.3% 0.214 259.815)', '#2b7fff', 1.0, CssColorNotation::Oklch];
        yield 'oklch outside sRGB is clipped' => ['oklch(72.3% 0.219 149.579)', '#00c950', 1.0, CssColorNotation::Oklch];
        yield 'oklch with alpha' => ['OKLCH(100% 0 0 / 0.1)', '#ffffff', 0.1, CssColorNotation::Oklch];
    }

    #[DataProvider('notAColorProvider')]
    public function testIgnoresValuesWithoutAKnownColor(string $value): void
    {
        self::assertNull((new CssColorParser())->parse($value));
    }

    /** @return iterable<string, array{string}> */
    public static function notAColorProvider(): iterable
    {
        yield 'current color' => ['currentColor'];
        yield 'variable' => ['var(--brand)'];
        yield 'nested function' => ['rgb(calc(1 + 2) 0 0)'];
        yield 'color mix' => ['color-mix(in srgb, red, blue)'];
        yield 'missing component' => ['rgb(1 2)'];
        yield 'two alphas' => ['rgb(1 2 3 / 1 / 1)'];
        yield 'none in legacy syntax' => ['rgb(none, 0, 0)'];
        yield 'percentage hue' => ['hsl(10% 50% 50%)'];
        yield 'five digit hex' => ['#12345'];
        yield 'unknown name' => ['brand'];
        yield 'unknown function' => ['foo(1 2 3)'];
        yield 'reference' => ['{colors.blue.500}'];
    }
}
