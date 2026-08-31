<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Symfony\Lsp\Document\Document;

final class TwigCallableDiagnosticProviderTest extends TwigCallableProviderTestCase
{
    public function testValidatesNamedArguments(): void
    {
        $extensionUri = 'file:///workspace/src/Twig/MediaExtension.php';
        $extensionText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\Attribute\AsTwigFilter;
            use Twig\Attribute\AsTwigFunction;
            use Twig\Environment;
            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class MediaExtension
            {
                private const DYNAMIC_OPTIONS = ['needs_environment' => true];

                public function getFilters(): array
                {
                    return [new TwigFilter('shorten', [MediaExtension::class, 'shorten'])];
                }

                public function getFunctions(): array
                {
                    return [
                        new TwigFunction('image', [MediaExtension::class, 'render'], ['needs_environment' => true, 'needs_context' => true]),
                        new TwigFunction('attrs', [MediaExtension::class, 'attrs'], ['is_variadic' => true]),
                        new TwigFunction('dynamic_image', [MediaExtension::class, 'dynamicImage'], self::DYNAMIC_OPTIONS),
                    ];
                }

                public function render(\Twig\Environment $environment, array $context, string $name, int $width = 200, bool $lazy = false): string
                {
                    return $name;
                }

                public function attrs(string $tag, array $arguments): string
                {
                    return $tag;
                }

                public function dynamicImage(\Twig\Environment $environment, string $name): string
                {
                    return $name;
                }

                public function shorten(string $value, int $length = 30): string
                {
                    return $value;
                }

                #[AsTwigFunction('attribute_image', needsContext: true)]
                public function attributeImage(Environment $environment, array $context, string $name, int $width = 200): string
                {
                    return $name;
                }

                #[AsTwigFilter('attribute_shorten')]
                public function attributeShorten(string $value, int $length = 30): string
                {
                    return $value;
                }

                #[AsTwigFunction('attribute_variadic')]
                public function attributeVariadic(string $name, mixed ...$options): string
                {
                    return $name;
                }
            }
            PHP;
        $environment = $this->providers([$extensionUri => $extensionText]);
        $provider = $environment['diagnostic'];
        $documents = $environment['documents'];
        $diagnostics = static function (string $text) use ($provider, $documents): ?array {
            $uri = 'file:///workspace/templates/diagnostics.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));

            return $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
        };

        $unknown = $diagnostics("{{ image(name: 'a', wdith: 3) }}\n{{ text|shorten(size: 5) }}\n");
        self::assertSame(
            ['Unknown argument "wdith" for Twig function "image".', 'Unknown argument "size" for Twig filter "shorten".'],
            array_column($unknown ?? [], 'message'),
        );
        self::assertSame([], $diagnostics("{{ image(name: 'a', width: 3, lazy: true) }}"));
        self::assertSame(
            ['Unknown argument "wdith" for Twig function "attribute_image".', 'Unknown argument "size" for Twig filter "attribute_shorten".'],
            array_column($diagnostics("{{ attribute_image(name: 'a', wdith: 3) }}\n{{ text|attribute_shorten(size: 5) }}\n") ?? [], 'message'),
        );
        self::assertSame([], $diagnostics("{{ image(name: 'x, wdith: 3') }}"));
        self::assertSame([], $diagnostics('{{ object.image(wdith: 3) }}'));
        self::assertSame([], $diagnostics("{{ attrs('div', data_test: 'x') }}"));
        self::assertSame([], $diagnostics("{{ attribute_variadic(name: 'a', unknown: 1) }}"));
        self::assertSame([], $diagnostics('{{ dynamic_image(unknown: 1) }}'));
        self::assertSame([], $diagnostics("{{ image({name: 'a'}) }}"));
        self::assertSame([], $diagnostics("{{ image(name ? 'a' : 'b') }}"));
        self::assertSame([], $diagnostics('{{ image(nested(), wdith: 3) }}'));
        self::assertSame([], $diagnostics('{{ unknown_callable(anything: 1) }}'));
        self::assertSame([], $diagnostics('{# {{ image(wdith: 3) }} #}'));
    }
}
