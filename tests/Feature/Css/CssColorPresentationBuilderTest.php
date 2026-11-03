<?php

namespace Symfony\Lsp\Tests\Feature\Css;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Color;
use Symfony\Lsp\Feature\Css\CssColorNotation;
use Symfony\Lsp\Feature\Css\CssColorParser;
use Symfony\Lsp\Feature\Css\CssColorPresentationBuilder;

final class CssColorPresentationBuilderTest extends TestCase
{
    #[DataProvider('formatProvider')]
    public function testFormatsColorsInEachNotation(CssColorNotation $notation, Color $color, ?string $expected): void
    {
        self::assertSame($expected, (new CssColorPresentationBuilder())->format($color, $notation));
    }

    /** @return iterable<string, array{CssColorNotation, Color, ?string}> */
    public static function formatProvider(): iterable
    {
        $blue = new Color(59 / 255, 130 / 255, 246 / 255);
        $translucent = new Color(59 / 255, 130 / 255, 246 / 255, 0.5);

        yield 'hex' => [CssColorNotation::Hex, $blue, '#3b82f6'];
        yield 'hex with alpha' => [CssColorNotation::Hex, $translucent, '#3b82f680'];
        yield 'named' => [CssColorNotation::Named, new Color(1.0, 0.0, 0.0), 'red'];
        yield 'transparent' => [CssColorNotation::Named, new Color(0.0, 0.0, 0.0, 0.0), 'transparent'];
        yield 'unnamed' => [CssColorNotation::Named, $blue, null];
        yield 'rgb' => [CssColorNotation::Rgb, $blue, 'rgb(59 130 246)'];
        yield 'rgb with alpha' => [CssColorNotation::Rgb, $translucent, 'rgb(59 130 246 / 0.5)'];
        yield 'hsl' => [CssColorNotation::Hsl, $blue, 'hsl(217.2 91.2% 59.8%)'];
        yield 'hsl hue wraps' => [CssColorNotation::Hsl, new Color(1.0, 0.0, 0.0001), 'hsl(0 100% 50%)'];
        yield 'hwb' => [CssColorNotation::Hwb, $blue, 'hwb(217.2 23.1% 3.5%)'];
        yield 'lab' => [CssColorNotation::Lab, new Color(1.0, 0.0, 0.0), 'lab(54.3% 80.8 69.89)'];
        yield 'lch' => [CssColorNotation::Lch, new Color(1.0, 0.0, 0.0), 'lch(54.3% 106.84 40.858)'];
        yield 'oklab' => [CssColorNotation::Oklab, new Color(1.0, 0.0, 0.0), 'oklab(62.8% 0.225 0.126)'];
        yield 'oklch' => [CssColorNotation::Oklch, $blue, 'oklch(62.3% 0.188 259.815)'];
        yield 'achromatic oklch' => [CssColorNotation::Oklch, new Color(1.0, 1.0, 1.0), 'oklch(100% 0 0)'];
    }

    public function testListsTheReplacedNotationFirstWithoutDuplicates(): void
    {
        $builder = new CssColorPresentationBuilder();
        $red = new Color(1.0, 0.0, 0.0);

        self::assertSame(['oklch(62.8% 0.258 29.234)', '#ff0000', 'rgb(255 0 0)', 'hsl(0 100% 50%)'], $builder->presentations($red, CssColorNotation::Oklch));
        self::assertSame(['red', '#ff0000', 'rgb(255 0 0)', 'hsl(0 100% 50%)', 'oklch(62.8% 0.258 29.234)'], $builder->presentations($red, CssColorNotation::Named));
        self::assertSame(['#3b82f6', 'rgb(59 130 246)', 'hsl(217.2 91.2% 59.8%)', 'oklch(62.3% 0.188 259.815)'], $builder->presentations(new Color(59 / 255, 130 / 255, 246 / 255), CssColorNotation::Named));
    }

    public function testRoundTripsThroughTheParser(): void
    {
        $builder = new CssColorPresentationBuilder();
        $parser = new CssColorParser();
        $color = new Color(0.2, 0.5, 0.9, 0.75);

        foreach (CssColorNotation::cases() as $notation) {
            $presentation = $builder->format($color, $notation);
            if (null === $presentation) {
                continue;
            }
            $parsed = $parser->parse($presentation);
            self::assertNotNull($parsed, $presentation);
            self::assertSame($notation, $parsed->notation, $presentation);
            foreach (['red', 'green', 'blue', 'alpha'] as $channel) {
                self::assertEqualsWithDelta($color->{$channel}, $parsed->color->{$channel}, 0.5 / 255, $presentation);
            }
        }
    }
}
