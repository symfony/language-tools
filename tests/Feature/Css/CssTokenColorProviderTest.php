<?php

namespace Symfony\Lsp\Tests\Feature\Css;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DocumentColorProviderRegistry;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class CssTokenColorProviderTest extends TestCase
{
    private const REFERENCE = <<<'PHP'
        <?php
        namespace Symfony\UX\Css;

        final class CssReference
        {
            public const TOKENS = [
                'colors' => [
                    'blue.500' => '#3b82f6',
                    'gray.900' => 'oklch(21% 0.034 264.665)',
                    'current' => 'currentColor',
                ],
                'spacing' => [
                    'md' => '1rem',
                ],
            ];
        }
        PHP;

    public function testShowsRawAndReferencedColorTokensInYaml(): void
    {
        $kit = $this->kit('config/packages/ux_css.yaml', <<<'YAML'
            ux_css:
                tokens:
                    colors:
                        brand: 'oklch(72.3% 0.219 149.579)'
                        blue: { 500: "#3b82f6", 600: rgb(37 99 235) }
                        current: currentColor
                        list: ['#fff']
                    spacing:
                        md: '#000'
                semantic_tokens:
                    colors:
                        primary: '{colors.blue.500}'
                        fg: { base: '{colors.gray.900}', _dark: '{ colors.unknown }' }
                        accent: '{colors.current}'
            when@dev:
                ux_css:
                    semantic-tokens:
                        colors:
                            debug: red
            framework:
                tokens:
                    colors:
                        other: red
            YAML);

        self::assertSame([
            'oklch(72.3% 0.219 149.579)' => '#00c950',
            '#3b82f6' => '#3b82f6',
            'rgb(37 99 235)' => '#2563eb',
            '{colors.blue.500}' => '#3b82f6',
            '{colors.gray.900}' => '#101828',
            'red' => '#ff0000',
        ], $this->colors($kit, 'config/packages/ux_css.yaml'));
    }

    public function testShowsColorTokensOfEveryPhpConfigurationShape(): void
    {
        $returned = <<<'PHP'
            <?php
            return [
                'ux_css' => ['tokens' => ['colors' => ['brand' => '#3b82f6', 'gray' => [900 => 'hsl(0 0% 10%)'], 'size' => 12]]],
                'when@dev' => ['ux_css' => ['semantic_tokens' => ['colors' => ['primary' => '{colors.blue.500}']]]],
                'framework' => ['tokens' => ['colors' => ['other' => 'red']]],
            ];
            PHP;
        $kit = $this->kit('config/packages/ux_css.php', $returned);
        self::assertSame(['#3b82f6' => '#3b82f6', 'hsl(0 0% 10%)' => '#1a1a1a', '{colors.blue.500}' => '#3b82f6'], $this->colors($kit, 'config/packages/ux_css.php'));

        $kit = $this->kit('config/packages/ux_css.php', <<<'PHP'
            <?php
            use Symfony\Config\App;

            return App::config(['ux_css' => ['semantic_tokens' => ['colors' => ['primary' => "{colors.blue.500}"]]]]);
            PHP);
        self::assertSame(['{colors.blue.500}' => '#3b82f6'], $this->colors($kit, 'config/packages/ux_css.php'));

        $kit = $this->kit('config/packages/ux_css.php', <<<'PHP'
            <?php
            return static function ($container): void {
                $container->extension('ux_css', ['tokens' => ['colors' => ['brand' => 'oklch(62.3% 0.214 259.815)']]]);
                $container->extension('framework', ['tokens' => ['colors' => ['other' => 'red']]]);
            };
            PHP);
        self::assertSame(['oklch(62.3% 0.214 259.815)' => '#2b7fff'], $this->colors($kit, 'config/packages/ux_css.php'));
    }

    public function testIgnoresFilesOutsideTheConfigurationDirectory(): void
    {
        $kit = $this->kit('src/ux_css.yaml', "ux_css:\n    tokens:\n        colors:\n            brand: red\n");

        self::assertSame([], $this->colors($kit, 'src/ux_css.yaml'));
    }

    public function testPresentsRawColorsInTheirNotationFirst(): void
    {
        $kit = $this->kit('config/packages/ux_css.yaml', <<<'YAML'
            ux_css:
                tokens:
                    colors:
                        brand: 'oklch(62.3% 0.214 259.815)'
                        plain: rgb(255 0 0)
                semantic_tokens:
                    colors:
                        primary: '{colors.blue.500}'
            YAML);
        $colors = $this->colorInformation($kit, 'config/packages/ux_css.yaml');

        self::assertSame(
            ['oklch(62.3% 0.214 259.815)', 'oklch(61.9% 0.207 259.253)', '#2b7fff', 'rgb(43 127 255)', 'hsl(216.3 100% 58.5%)'],
            array_column($this->presentations($kit, $colors[0]['range'], $colors[0]['color']), 'label'),
        );
        self::assertSame(
            ['oklch(62.8% 0.258 29.234)', '#ff0000', 'rgb(255 0 0)', 'hsl(0 100% 50%)'],
            array_column($this->presentations($kit, $colors[0]['range'], ['red' => 1, 'green' => 0, 'blue' => 0, 'alpha' => 1]), 'label'),
        );

        $plain = $this->presentations($kit, $colors[1]['range'], ['red' => 0, 'green' => 0, 'blue' => 1, 'alpha' => 1]);
        self::assertSame(['label' => 'rgb(0 0 255)'], $plain[0]);
        self::assertSame(['label' => '#0000ff', 'textEdit' => ['range' => $colors[1]['range'], 'newText' => "'#0000ff'"]], $plain[1]);

        self::assertSame([], $this->presentations($kit, $colors[2]['range'], ['red' => 1, 'green' => 0, 'blue' => 0, 'alpha' => 1]));
    }

    private function kit(string $path, string $text): ProjectTestKit
    {
        return (new ProjectTestKit())
            ->open('file:///workspace/config/reference_css.php', self::REFERENCE)
            ->open('file:///workspace/'.$path, $text)
            ->index();
    }

    private function registry(ProjectTestKit $kit): DocumentColorProviderRegistry
    {
        return $kit->get(DocumentColorProviderRegistry::class);
    }

    /** @return array<string, string> the hexadecimal color shown for each value, by its text */
    private function colors(ProjectTestKit $kit, string $path): array
    {
        $uri = 'file:///workspace/'.$path;
        $lines = explode("\n", $kit->document($uri)->document->text);
        $colors = [];
        foreach ($this->colorInformation($kit, $path) as $information) {
            ['start' => $start, 'end' => $end] = $information['range'];
            $value = substr($lines[$start['line']], $start['character'], $end['character'] - $start['character']);
            $colors[$value] = \sprintf('#%02x%02x%02x', round($information['color']['red'] * 255), round($information['color']['green'] * 255), round($information['color']['blue'] * 255));
        }

        return $colors;
    }

    /** @return list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, color: array{red: float, green: float, blue: float, alpha: float}}> */
    private function colorInformation(ProjectTestKit $kit, string $path): array
    {
        /** @var list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, color: array{red: float, green: float, blue: float, alpha: float}}> $colors */
        $colors = $this->registry($kit)->colors(LspRequests::document('file:///workspace/'.$path));

        return $colors;
    }

    /**
     * @param array<array-key, mixed>  $range
     * @param array<string, int|float> $color
     *
     * @return list<array<array-key, mixed>>
     */
    private function presentations(ProjectTestKit $kit, array $range, array $color): array
    {
        return $this->registry($kit)->presentations([
            ...LspRequests::document('file:///workspace/config/packages/ux_css.yaml'),
            'range' => $range,
            'color' => $color,
        ]);
    }
}
