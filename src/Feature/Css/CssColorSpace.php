<?php

namespace Symfony\Lsp\Feature\Css;

/**
 * Conversions between sRGB and the color spaces of CSS Color 4, with the
 * matrices of its sample code; Lab and LCH use the D50 white point.
 */
final class CssColorSpace
{
    private const KAPPA = 24389 / 27;
    private const EPSILON = 216 / 24389;
    private const D50 = [0.3457 / 0.3585, 1.0, (1.0 - 0.3457 - 0.3585) / 0.3585];

    /** @return array{float, float, float} */
    public static function hslToSrgb(float $hue, float $saturation, float $lightness): array
    {
        $hue = fmod(fmod($hue, 360) + 360, 360);
        $amount = $saturation * min($lightness, 1 - $lightness);
        $channel = static function (int $n) use ($hue, $lightness, $amount): float {
            $k = fmod($n + $hue / 30, 12);

            return $lightness - $amount * max(-1, min($k - 3, 9 - $k, 1));
        };

        return [$channel(0), $channel(8), $channel(4)];
    }

    /** @return array{float, float, float} hue in degrees, saturation and lightness between 0 and 1 */
    public static function srgbToHsl(float $red, float $green, float $blue): array
    {
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;
        if ($delta <= 0.0) {
            return [0.0, 0.0, $lightness];
        }
        $saturation = 0.0 === $lightness || 1.0 === $lightness ? 0.0 : ($max - $lightness) / min($lightness, 1 - $lightness);
        $hue = match ($max) {
            $red => ($green - $blue) / $delta + ($green < $blue ? 6 : 0),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        return [$hue * 60, $saturation, $lightness];
    }

    /** @return array{float, float, float} */
    public static function hwbToSrgb(float $hue, float $whiteness, float $blackness): array
    {
        if ($whiteness + $blackness >= 1) {
            $gray = $whiteness / ($whiteness + $blackness);

            return [$gray, $gray, $gray];
        }
        $rgb = self::hslToSrgb($hue, 1, 0.5);

        return array_map(static fn (float $channel): float => $channel * (1 - $whiteness - $blackness) + $whiteness, $rgb);
    }

    /** @return array{float, float, float} hue in degrees, whiteness and blackness between 0 and 1 */
    public static function srgbToHwb(float $red, float $green, float $blue): array
    {
        return [self::srgbToHsl($red, $green, $blue)[0], min($red, $green, $blue), 1 - max($red, $green, $blue)];
    }

    /** @return array{float, float, float} */
    public static function oklabToSrgb(float $lightness, float $a, float $b): array
    {
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return self::linearToSrgb(
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        );
    }

    /** @return array{float, float, float} */
    public static function srgbToOklab(float $red, float $green, float $blue): array
    {
        [$red, $green, $blue] = self::srgbToLinear($red, $green, $blue);
        $l = self::cbrt(0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue);
        $m = self::cbrt(0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue);
        $s = self::cbrt(0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @return array{float, float, float} */
    public static function labToSrgb(float $lightness, float $a, float $b): array
    {
        $f1 = ($lightness + 16) / 116;
        $f0 = $a / 500 + $f1;
        $f2 = $f1 - $b / 200;
        $x = $f0 ** 3 > self::EPSILON ? $f0 ** 3 : (116 * $f0 - 16) / self::KAPPA;
        $y = $lightness > self::KAPPA * self::EPSILON ? $f1 ** 3 : $lightness / self::KAPPA;
        $z = $f2 ** 3 > self::EPSILON ? $f2 ** 3 : (116 * $f2 - 16) / self::KAPPA;
        [$x, $y, $z] = self::multiply([
            [0.955473421488075, -0.02309845494876471, 0.06325924320057072],
            [-0.0283697093338637, 1.0099953980813041, 0.021041441191917323],
            [0.012314014864481998, -0.020507649298898964, 1.330365926242124],
        ], [$x * self::D50[0], $y * self::D50[1], $z * self::D50[2]]);

        return self::linearToSrgb(...self::multiply([
            [3.2409699419045226, -1.537383177570094, -0.4986107602930034],
            [-0.9692436362808796, 1.8759675015077202, 0.04155505740717559],
            [0.05563007969699366, -0.20397695888897652, 1.0569715142428786],
        ], [$x, $y, $z]));
    }

    /** @return array{float, float, float} */
    public static function srgbToLab(float $red, float $green, float $blue): array
    {
        $xyz = self::multiply([
            [0.41239079926595934, 0.357584339383878, 0.1804807884018343],
            [0.21263900587151027, 0.715168678767756, 0.07219231536073371],
            [0.01933081871559182, 0.11919477979462598, 0.9505321522496607],
        ], self::srgbToLinear($red, $green, $blue));
        $xyz = self::multiply([
            [1.0479297925449969, 0.022946870601609652, -0.05019226628920524],
            [0.02962780877005599, 0.9904344267538799, -0.017073799063418826],
            [-0.009243040646204504, 0.015055191490298152, 0.7518742814281371],
        ], $xyz);
        [$f0, $f1, $f2] = array_map(
            static fn (float $value, float $white): float => $value / $white > self::EPSILON ? self::cbrt($value / $white) : (self::KAPPA * $value / $white + 16) / 116,
            $xyz,
            self::D50,
        );

        return [116 * $f1 - 16, 500 * ($f0 - $f1), 200 * ($f1 - $f2)];
    }

    /** @return array{float, float} the a and b axes of a chroma and a hue in degrees */
    public static function polarToAxes(float $chroma, float $hue): array
    {
        $radians = deg2rad($hue);

        return [$chroma * cos($radians), $chroma * sin($radians)];
    }

    /** @return array{float, float} the chroma and the hue in degrees */
    public static function axesToPolar(float $a, float $b): array
    {
        $hue = rad2deg(atan2($b, $a));

        return [sqrt($a ** 2 + $b ** 2), $hue < 0 ? $hue + 360 : $hue];
    }

    /** @return array{float, float, float} */
    private static function srgbToLinear(float $red, float $green, float $blue): array
    {
        return array_map(static function (float $channel): float {
            $magnitude = abs($channel);

            return $magnitude <= 0.04045 ? $channel / 12.92 : ($channel <=> 0) * (($magnitude + 0.055) / 1.055) ** 2.4;
        }, [$red, $green, $blue]);
    }

    /** @return array{float, float, float} */
    private static function linearToSrgb(float $red, float $green, float $blue): array
    {
        return array_map(static function (float $channel): float {
            $magnitude = abs($channel);

            return $magnitude <= 0.0031308 ? 12.92 * $channel : ($channel <=> 0) * (1.055 * $magnitude ** (1 / 2.4) - 0.055);
        }, [$red, $green, $blue]);
    }

    /**
     * @param array{array{float, float, float}, array{float, float, float}, array{float, float, float}} $matrix
     * @param array{float, float, float}                                                                $vector
     *
     * @return array{float, float, float}
     */
    private static function multiply(array $matrix, array $vector): array
    {
        return array_map(
            static fn (array $row): float => $row[0] * $vector[0] + $row[1] * $vector[1] + $row[2] * $vector[2],
            $matrix,
        );
    }

    private static function cbrt(float $value): float
    {
        return ($value <=> 0) * abs($value) ** (1 / 3);
    }
}
