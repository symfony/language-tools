<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Symfony\Lsp\Document\Document;

final class TwigCallableCompletionProviderTest extends TwigCallableProviderTestCase
{
    public function testCompletesCustomTwigFunctionsAndFilters(): void
    {
        $extensionUri = 'file:///workspace/src/Twig/AppExtension.php';
        $extensionText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\Attribute\AsTwigFilter;
            use Twig\Attribute\AsTwigFunction;
            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class AppExtension
            {
                public function getFilters(): array
                {
                    return [new TwigFilter('filter_name', [AppExtensionRuntime::class, 'doSomething'])];
                }

                public function getFunctions(): array
                {
                    return [new TwigFunction('function_name', [AppExtensionRuntime::class, 'doSomething'])];
                }

                #[AsTwigFunction('attribute_function')]
                public function attributeFunction(string $value): string { return $value; }

                #[AsTwigFilter('attribute_filter')]
                public function attributeFilter(string $value): string { return $value; }
            }
            PHP;
        $environment = $this->providers([$extensionUri => $extensionText]);
        $provider = $environment['completion'];
        $documents = $environment['documents'];
        $converter = $environment['converter'];
        $completions = static function (string $text) use ($provider, $documents, $converter): ?array {
            $uri = 'file:///workspace/templates/completion.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, \strlen($text));
            $items = $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]);

            return null === $items ? null : array_column($items, 'label');
        };

        self::assertSame(['function_name'], $completions('{{ func'));
        self::assertSame(['function_name'], $completions('{{ function_name'));
        self::assertSame(['attribute_function'], $completions('{{ attribute_f'));
        self::assertSame(['attribute_filter', 'filter_name'], $completions('{{ item|'));
        self::assertSame(['filter_name'], $completions('{{ item|fil'));
        self::assertSame(['attribute_filter'], $completions('{{ item|attribute_f'));
        self::assertSame(['function_name'], $completions('{% if f'));
        self::assertNull($completions('{% macro function_n'));
        self::assertSame(['function_name'], $completions('{{ "}}" ~ func'));
        self::assertNull($completions('{{ "say func'));
        self::assertNull($completions('{{ item.func'));
        self::assertNull($completions('Plain func'));
        self::assertNull($completions('{{ done }} func'));
    }

    public function testCompletesNamedArguments(): void
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
                private const DYNAMIC_FLAG = true;
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

                public function render(\Twig\Environment $environment, array $context, string $name, int $width = 200, bool $lazy = false, string $pattern = "prefix {$phantom}"): string
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

                #[AsTwigFunction('dynamic_attribute_charset', needsCharset: self::DYNAMIC_FLAG)]
                public function dynamicAttributeCharset(string $charset, string $value): string
                {
                    return $value;
                }

                #[AsTwigFunction('dynamic_attribute_sandbox', needsIsSandboxed: self::DYNAMIC_FLAG)]
                public function dynamicAttributeSandbox(bool $isSandboxed, string $value): string
                {
                    return $value;
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

                #[AsTwigFunction('legacy_safe', null, null, null, ['html'])]
                public function legacySafe(string $name, int $width = 200): string
                {
                    return $name;
                }
            }
            PHP;
        $environment = $this->providers([$extensionUri => $extensionText]);
        $provider = $environment['completion'];
        $documents = $environment['documents'];
        $converter = $environment['converter'];
        $completions = static function (string $text) use ($provider, $documents, $converter): ?array {
            $uri = 'file:///workspace/templates/arguments.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, \strlen($text));
            $items = $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]);

            return null === $items ? null : array_column($items, 'label');
        };

        self::assertSame(['name', 'width', 'lazy', 'pattern'], $completions('{{ image('));
        self::assertSame(['width'], $completions("{{ image(name: 'a', w"));
        self::assertSame(['width'], $completions("{{ image(name: 'a, lazy: false', w"));
        self::assertSame(['width', 'lazy', 'pattern'], $completions("{{ image(name: 'a', "));
        self::assertSame(['tag'], $completions('{{ attrs('));
        self::assertSame(['name'], $completions('{{ dynamic_image('));
        self::assertSame(['length'], $completions('{{ text|shorten('));
        self::assertSame(['name', 'width'], $completions('{{ attribute_image('));
        self::assertSame(['value'], $completions('{{ dynamic_attribute_charset('));
        self::assertSame(['value'], $completions('{{ dynamic_attribute_sandbox('));
        self::assertSame(['length'], $completions('{{ text|attribute_shorten('));
        self::assertSame(['name'], $completions('{{ attribute_variadic('));
        self::assertSame(['name', 'width'], $completions('{{ legacy_safe('));
        self::assertNull($completions('{% macro image(name, '));
        self::assertNull($completions("{{ image(name: 'a"));
    }

    public function testCompletesDirectivesEmbeddedInQuotedMarkupAttributes(): void
    {
        $extensionUri = 'file:///workspace/src/Twig/Extensions.php';
        $extensionText = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\TwigFilter;
            use Twig\TwigFunction;

            final class Extensions
            {
                public function getFilters(): array
                {
                    return [
                        new TwigFilter('docu_link', [Extensions::class, 'documentationLink']),
                        new TwigFilter('icon', [Extensions::class, 'icon']),
                    ];
                }

                public function getFunctions(): array
                {
                    return [new TwigFunction('icon', [Extensions::class, 'icon'])];
                }

                public function documentationLink(string $url = ''): string
                {
                    return $url;
                }

                public function icon(string $name, bool $onlyIcon = false): string
                {
                    return $name;
                }
            }
            PHP;
        $environment = $this->providers([$extensionUri => $extensionText]);
        $provider = $environment['completion'];
        $documents = $environment['documents'];
        $converter = $environment['converter'];
        $items = static function (string $text, ?int $offset = null) use ($provider, $documents, $converter): ?array {
            $uri = 'file:///workspace/templates/about/license.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, $offset ?? \strlen($text));

            return $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]);
        };
        $labels = static function (string $text, ?int $offset = null) use ($items): ?array {
            $completions = $items($text, $offset);

            return null === $completions ? null : array_column($completions, 'label');
        };
        $textEdit = static fn (string $text, ?int $offset = null): mixed => $items($text, $offset)[0]['textEdit'] ?? null;

        $link = '<a href="{{ \'\'|docu_link }}" target="_blank" class="card-btn">';
        $cursor = (int) strpos($link, ' }}');
        self::assertSame(['docu_link'], $labels($link, $cursor));
        self::assertSame(
            ['range' => ['start' => ['line' => 0, 'character' => 15], 'end' => ['line' => 0, 'character' => 24]], 'newText' => 'docu_link'],
            $textEdit($link, $cursor),
        );
        self::assertSame(['docu_link'], $labels($link, $cursor - 4));

        $accented = '<a title="Créé" href="{{ \'\'|docu_l';
        self::assertSame(['docu_link'], $labels($accented));
        self::assertSame(
            ['range' => ['start' => ['line' => 0, 'character' => 28], 'end' => ['line' => 0, 'character' => 34]], 'newText' => 'docu_link'],
            $textEdit($accented),
        );

        $icon = '<i class="fa {{ \'help\'|icon(';
        self::assertSame(['onlyIcon'], $labels($icon));
        self::assertSame(
            ['range' => ['start' => ['line' => 0, 'character' => 28], 'end' => ['line' => 0, 'character' => 28]], 'newText' => 'onlyIcon'],
            $textEdit($icon),
        );
        self::assertSame(['onlyIcon'], $labels($icon.'only'));
        self::assertSame(
            ['range' => ['start' => ['line' => 0, 'character' => 28], 'end' => ['line' => 0, 'character' => 32]], 'newText' => 'onlyIcon'],
            $textEdit($icon.'only'),
        );

        self::assertSame(['icon'], $labels('<i class="fa {{ ic'));
        self::assertSame([], $labels('<i class="fa {{ \'help\'|icon(onlyIcon: false, '));
        self::assertNull($labels('<a href="{{ \'docu_l'));
        self::assertNull($labels('<i class="fa {{ \'help\'|icon(\'fa-ic'));
        self::assertNull($labels('<a href="{# {{ \'\'|docu_l'));
        self::assertNull($labels('<a href="docu_l'));
        self::assertNull($labels('<a href="{{ \'\'|docu_link }}" title="docu_l'));
        self::assertNull($labels('<div>{% macro ic'));
    }
}
