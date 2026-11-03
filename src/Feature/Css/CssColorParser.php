<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\Color;

/**
 * Parses the CSS colors whose value is known without a document: hexadecimal
 * and named colors, and the rgb(), hsl(), hwb(), lab(), lch(), oklab() and
 * oklch() functions; colors outside sRGB are clipped.
 */
final class CssColorParser
{
    public const NAMED = [
        'aliceblue' => 'f0f8ff',
        'antiquewhite' => 'faebd7',
        'aqua' => '00ffff',
        'aquamarine' => '7fffd4',
        'azure' => 'f0ffff',
        'beige' => 'f5f5dc',
        'bisque' => 'ffe4c4',
        'black' => '000000',
        'blanchedalmond' => 'ffebcd',
        'blue' => '0000ff',
        'blueviolet' => '8a2be2',
        'brown' => 'a52a2a',
        'burlywood' => 'deb887',
        'cadetblue' => '5f9ea0',
        'chartreuse' => '7fff00',
        'chocolate' => 'd2691e',
        'coral' => 'ff7f50',
        'cornflowerblue' => '6495ed',
        'cornsilk' => 'fff8dc',
        'crimson' => 'dc143c',
        'cyan' => '00ffff',
        'darkblue' => '00008b',
        'darkcyan' => '008b8b',
        'darkgoldenrod' => 'b8860b',
        'darkgray' => 'a9a9a9',
        'darkgreen' => '006400',
        'darkgrey' => 'a9a9a9',
        'darkkhaki' => 'bdb76b',
        'darkmagenta' => '8b008b',
        'darkolivegreen' => '556b2f',
        'darkorange' => 'ff8c00',
        'darkorchid' => '9932cc',
        'darkred' => '8b0000',
        'darksalmon' => 'e9967a',
        'darkseagreen' => '8fbc8f',
        'darkslateblue' => '483d8b',
        'darkslategray' => '2f4f4f',
        'darkslategrey' => '2f4f4f',
        'darkturquoise' => '00ced1',
        'darkviolet' => '9400d3',
        'deeppink' => 'ff1493',
        'deepskyblue' => '00bfff',
        'dimgray' => '696969',
        'dimgrey' => '696969',
        'dodgerblue' => '1e90ff',
        'firebrick' => 'b22222',
        'floralwhite' => 'fffaf0',
        'forestgreen' => '228b22',
        'fuchsia' => 'ff00ff',
        'gainsboro' => 'dcdcdc',
        'ghostwhite' => 'f8f8ff',
        'gold' => 'ffd700',
        'goldenrod' => 'daa520',
        'gray' => '808080',
        'green' => '008000',
        'greenyellow' => 'adff2f',
        'grey' => '808080',
        'honeydew' => 'f0fff0',
        'hotpink' => 'ff69b4',
        'indianred' => 'cd5c5c',
        'indigo' => '4b0082',
        'ivory' => 'fffff0',
        'khaki' => 'f0e68c',
        'lavender' => 'e6e6fa',
        'lavenderblush' => 'fff0f5',
        'lawngreen' => '7cfc00',
        'lemonchiffon' => 'fffacd',
        'lightblue' => 'add8e6',
        'lightcoral' => 'f08080',
        'lightcyan' => 'e0ffff',
        'lightgoldenrodyellow' => 'fafad2',
        'lightgray' => 'd3d3d3',
        'lightgreen' => '90ee90',
        'lightgrey' => 'd3d3d3',
        'lightpink' => 'ffb6c1',
        'lightsalmon' => 'ffa07a',
        'lightseagreen' => '20b2aa',
        'lightskyblue' => '87cefa',
        'lightslategray' => '778899',
        'lightslategrey' => '778899',
        'lightsteelblue' => 'b0c4de',
        'lightyellow' => 'ffffe0',
        'lime' => '00ff00',
        'limegreen' => '32cd32',
        'linen' => 'faf0e6',
        'magenta' => 'ff00ff',
        'maroon' => '800000',
        'mediumaquamarine' => '66cdaa',
        'mediumblue' => '0000cd',
        'mediumorchid' => 'ba55d3',
        'mediumpurple' => '9370db',
        'mediumseagreen' => '3cb371',
        'mediumslateblue' => '7b68ee',
        'mediumspringgreen' => '00fa9a',
        'mediumturquoise' => '48d1cc',
        'mediumvioletred' => 'c71585',
        'midnightblue' => '191970',
        'mintcream' => 'f5fffa',
        'mistyrose' => 'ffe4e1',
        'moccasin' => 'ffe4b5',
        'navajowhite' => 'ffdead',
        'navy' => '000080',
        'oldlace' => 'fdf5e6',
        'olive' => '808000',
        'olivedrab' => '6b8e23',
        'orange' => 'ffa500',
        'orangered' => 'ff4500',
        'orchid' => 'da70d6',
        'palegoldenrod' => 'eee8aa',
        'palegreen' => '98fb98',
        'paleturquoise' => 'afeeee',
        'palevioletred' => 'db7093',
        'papayawhip' => 'ffefd5',
        'peachpuff' => 'ffdab9',
        'peru' => 'cd853f',
        'pink' => 'ffc0cb',
        'plum' => 'dda0dd',
        'powderblue' => 'b0e0e6',
        'purple' => '800080',
        'rebeccapurple' => '663399',
        'red' => 'ff0000',
        'rosybrown' => 'bc8f8f',
        'royalblue' => '4169e1',
        'saddlebrown' => '8b4513',
        'salmon' => 'fa8072',
        'sandybrown' => 'f4a460',
        'seagreen' => '2e8b57',
        'seashell' => 'fff5ee',
        'sienna' => 'a0522d',
        'silver' => 'c0c0c0',
        'skyblue' => '87ceeb',
        'slateblue' => '6a5acd',
        'slategray' => '708090',
        'slategrey' => '708090',
        'snow' => 'fffafa',
        'springgreen' => '00ff7f',
        'steelblue' => '4682b4',
        'tan' => 'd2b48c',
        'teal' => '008080',
        'thistle' => 'd8bfd8',
        'tomato' => 'ff6347',
        'turquoise' => '40e0d0',
        'violet' => 'ee82ee',
        'wheat' => 'f5deb3',
        'white' => 'ffffff',
        'whitesmoke' => 'f5f5f5',
        'yellow' => 'ffff00',
        'yellowgreen' => '9acd32',
    ];

    private const NUMBER = '[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?';

    public function parse(string $value): ?CssColor
    {
        $value = strtolower(trim($value));
        if (1 === preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $value, $match)) {
            return new CssColor($this->hex($match[1]), CssColorNotation::Hex);
        }
        if ('transparent' === $value) {
            return new CssColor(new Color(0.0, 0.0, 0.0, 0.0), CssColorNotation::Named);
        }
        if (isset(self::NAMED[$value])) {
            return new CssColor($this->hex(self::NAMED[$value]), CssColorNotation::Named);
        }
        if (1 !== preg_match('/^([a-z]+)\(\s*([^()]*?)\s*\)$/D', $value, $match)) {
            return null;
        }
        $arguments = $this->arguments($match[2]);
        if (null === $arguments) {
            return null;
        }
        [$components, $alpha] = $arguments;
        $alpha = null === $alpha ? 1.0 : $this->alpha($alpha);
        $rgb = match ($match[1]) {
            'rgb', 'rgba' => $this->rgb($components),
            'hsl', 'hsla' => $this->cylindrical($components, CssColorSpace::hslToSrgb(...)),
            'hwb' => $this->cylindrical($components, CssColorSpace::hwbToSrgb(...)),
            'lab' => $this->lab($components, 100, 125, CssColorSpace::labToSrgb(...)),
            'oklab' => $this->lab($components, 1, 0.4, CssColorSpace::oklabToSrgb(...)),
            'lch' => $this->lch($components, 100, 150, CssColorSpace::labToSrgb(...)),
            'oklch' => $this->lch($components, 1, 0.4, CssColorSpace::oklabToSrgb(...)),
            default => null,
        };
        if (null === $rgb || null === $alpha) {
            return null;
        }
        $notation = match ($match[1]) {
            'rgb', 'rgba' => CssColorNotation::Rgb,
            'hsl', 'hsla' => CssColorNotation::Hsl,
            'hwb' => CssColorNotation::Hwb,
            'lab' => CssColorNotation::Lab,
            'oklab' => CssColorNotation::Oklab,
            'lch' => CssColorNotation::Lch,
            default => CssColorNotation::Oklch,
        };
        [$red, $green, $blue] = array_map(static fn (float $channel): float => max(0.0, min(1.0, $channel)), $rgb);

        return new CssColor(new Color($red, $green, $blue, $alpha), $notation);
    }

    private function hex(string $digits): Color
    {
        if (\strlen($digits) <= 4) {
            $digits = implode('', array_map(static fn (string $digit): string => $digit.$digit, str_split($digits)));
        }
        $channels = array_map(static fn (string $pair): float => hexdec($pair) / 255, str_split($digits, 2));

        return new Color($channels[0], $channels[1], $channels[2], $channels[3] ?? 1.0);
    }

    /** @return array{list<string>, ?string}|null */
    private function arguments(string $arguments): ?array
    {
        if (str_contains($arguments, ',')) {
            $components = array_map(trim(...), explode(',', $arguments));
            if (!\in_array(\count($components), [3, 4], true) || \in_array('none', $components, true)) {
                return null;
            }

            return [\array_slice($components, 0, 3), $components[3] ?? null];
        }
        $parts = explode('/', $arguments);
        if (\count($parts) > 2) {
            return null;
        }
        $components = preg_split('/\s+/', trim($parts[0]));
        if (false === $components || 3 !== \count($components)) {
            return null;
        }
        $alpha = isset($parts[1]) ? trim($parts[1]) : null;

        return '' === $alpha ? null : [$components, $alpha];
    }

    /**
     * @param list<string> $components
     *
     * @return array{float, float, float}|null
     */
    private function rgb(array $components): ?array
    {
        $channels = [];
        foreach ($components as $component) {
            $channel = $this->number($component, 255);
            if (null === $channel) {
                return null;
            }
            $channels[] = $channel / 255;
        }

        return [$channels[0], $channels[1], $channels[2]];
    }

    /**
     * @param list<string>                                              $components
     * @param \Closure(float, float, float): array{float, float, float} $toSrgb
     *
     * @return array{float, float, float}|null
     */
    private function cylindrical(array $components, \Closure $toSrgb): ?array
    {
        $hue = $this->hue($components[0]);
        $first = $this->number($components[1], 100);
        $second = $this->number($components[2], 100);
        if (null === $hue || null === $first || null === $second) {
            return null;
        }

        return $toSrgb($hue, max(0.0, min(1.0, $first / 100)), max(0.0, min(1.0, $second / 100)));
    }

    /**
     * @param list<string>                                              $components
     * @param \Closure(float, float, float): array{float, float, float} $toSrgb
     *
     * @return array{float, float, float}|null
     */
    private function lab(array $components, float $lightnessScale, float $axisScale, \Closure $toSrgb): ?array
    {
        $lightness = $this->number($components[0], $lightnessScale);
        $a = $this->number($components[1], $axisScale);
        $b = $this->number($components[2], $axisScale);
        if (null === $lightness || null === $a || null === $b) {
            return null;
        }

        return $toSrgb(max(0.0, min($lightnessScale, $lightness)), $a, $b);
    }

    /**
     * @param list<string>                                              $components
     * @param \Closure(float, float, float): array{float, float, float} $toSrgb
     *
     * @return array{float, float, float}|null
     */
    private function lch(array $components, float $lightnessScale, float $chromaScale, \Closure $toSrgb): ?array
    {
        $lightness = $this->number($components[0], $lightnessScale);
        $chroma = $this->number($components[1], $chromaScale);
        $hue = $this->hue($components[2]);
        if (null === $lightness || null === $chroma || null === $hue) {
            return null;
        }

        return $toSrgb(max(0.0, min($lightnessScale, $lightness)), ...CssColorSpace::polarToAxes(max(0.0, $chroma), $hue));
    }

    private function alpha(string $component): ?float
    {
        $alpha = $this->number($component, 1);

        return null === $alpha ? null : max(0.0, min(1.0, $alpha));
    }

    /** A number, or a percentage of the given scale. */
    private function number(string $component, float $percentScale): ?float
    {
        if ('none' === $component) {
            return 0.0;
        }
        if (1 !== preg_match('/^('.self::NUMBER.')(%?)$/D', $component, $match)) {
            return null;
        }

        return '%' === $match[2] ? (float) $match[1] * $percentScale / 100 : (float) $match[1];
    }

    /** A hue in degrees. */
    private function hue(string $component): ?float
    {
        if ('none' === $component) {
            return 0.0;
        }
        if (1 !== preg_match('/^('.self::NUMBER.')(deg|grad|rad|turn)?$/D', $component, $match)) {
            return null;
        }

        return match ($match[2] ?? '') {
            'grad' => (float) $match[1] * 0.9,
            'rad' => rad2deg((float) $match[1]),
            'turn' => (float) $match[1] * 360,
            default => (float) $match[1],
        };
    }
}
