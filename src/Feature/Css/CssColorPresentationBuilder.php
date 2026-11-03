<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\Color;

final class CssColorPresentationBuilder
{
    private const ALTERNATIVES = [CssColorNotation::Hex, CssColorNotation::Rgb, CssColorNotation::Hsl, CssColorNotation::Oklch];

    /**
     * The color written in the notation of the value it replaces first, then
     * in the most common notations.
     *
     * @return list<string>
     */
    public function presentations(Color $color, CssColorNotation $notation): array
    {
        $presentations = [];
        foreach ([$notation, ...self::ALTERNATIVES] as $candidate) {
            $presentation = $this->format($color, $candidate);
            if (null !== $presentation && !\in_array($presentation, $presentations, true)) {
                $presentations[] = $presentation;
            }
        }

        return $presentations;
    }

    public function format(Color $color, CssColorNotation $notation): ?string
    {
        $red = max(0.0, min(1.0, $color->red));
        $green = max(0.0, min(1.0, $color->green));
        $blue = max(0.0, min(1.0, $color->blue));
        $alpha = max(0.0, min(1.0, $color->alpha));

        return match ($notation) {
            CssColorNotation::Hex => $this->hex($red, $green, $blue, $alpha),
            CssColorNotation::Named => $this->named($red, $green, $blue, $alpha),
            CssColorNotation::Rgb => $this->function('rgb', [
                $this->number($red * 255, 0),
                $this->number($green * 255, 0),
                $this->number($blue * 255, 0),
            ], $alpha),
            CssColorNotation::Hsl => $this->cylindrical('hsl', CssColorSpace::srgbToHsl($red, $green, $blue), $alpha),
            CssColorNotation::Hwb => $this->cylindrical('hwb', CssColorSpace::srgbToHwb($red, $green, $blue), $alpha),
            CssColorNotation::Lab => $this->lab('lab', CssColorSpace::srgbToLab($red, $green, $blue), $alpha),
            CssColorNotation::Lch => $this->lch('lch', CssColorSpace::srgbToLab($red, $green, $blue), $alpha),
            CssColorNotation::Oklab => $this->lab('oklab', CssColorSpace::srgbToOklab($red, $green, $blue), $alpha, 100),
            CssColorNotation::Oklch => $this->lch('oklch', CssColorSpace::srgbToOklab($red, $green, $blue), $alpha, 100),
        };
    }

    private function hex(float $red, float $green, float $blue, float $alpha): string
    {
        $channels = 1.0 > $alpha ? [$red, $green, $blue, $alpha] : [$red, $green, $blue];

        return '#'.implode('', array_map(static fn (float $channel): string => \sprintf('%02x', (int) round($channel * 255)), $channels));
    }

    private function named(float $red, float $green, float $blue, float $alpha): ?string
    {
        if (0.0 === $alpha) {
            return 'transparent';
        }
        $hex = $this->hex($red, $green, $blue, $alpha);
        $name = array_search(substr($hex, 1), CssColorParser::NAMED, true);

        return false === $name ? null : $name;
    }

    /** @param array{float, float, float} $hsl */
    private function cylindrical(string $function, array $hsl, float $alpha): string
    {
        return $this->function($function, [
            $this->hue($hsl[0], 1),
            $this->number($hsl[1] * 100, 1).'%',
            $this->number($hsl[2] * 100, 1).'%',
        ], $alpha);
    }

    /** @param array{float, float, float} $lab */
    private function lab(string $function, array $lab, float $alpha, float $lightnessScale = 1): string
    {
        $decimals = 1.0 === $lightnessScale ? 2 : 3;

        return $this->function($function, [
            $this->number($lab[0] * $lightnessScale, 1).'%',
            $this->number($lab[1], $decimals),
            $this->number($lab[2], $decimals),
        ], $alpha);
    }

    /** @param array{float, float, float} $lab */
    private function lch(string $function, array $lab, float $alpha, float $lightnessScale = 1): string
    {
        [$chroma, $hue] = CssColorSpace::axesToPolar($lab[1], $lab[2]);
        $decimals = 1.0 === $lightnessScale ? 2 : 3;
        $chroma = round($chroma, $decimals);

        return $this->function($function, [
            $this->number($lab[0] * $lightnessScale, 1).'%',
            $this->number($chroma, $decimals),
            0.0 === $chroma ? '0' : $this->hue($hue, 3),
        ], $alpha);
    }

    /** @param list<string> $components */
    private function function(string $function, array $components, float $alpha): string
    {
        $alpha = round($alpha, 3);

        return $function.'('.implode(' ', $components).(1.0 > $alpha ? ' / '.$this->number($alpha, 3) : '').')';
    }

    private function hue(float $degrees, int $decimals): string
    {
        return $this->number(fmod(round($degrees, $decimals), 360), $decimals);
    }

    private function number(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return '-0' === $formatted ? '0' : $formatted;
    }
}
