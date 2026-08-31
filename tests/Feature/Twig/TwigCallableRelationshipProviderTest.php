<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableRelationshipProviderTest extends TwigCallableProviderTestCase
{
    public function testProvidesHoverDefinitionAndReferencesForCustomTwigFunctionsAndFilters(): void
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
                    return [
                        new TwigFunction('function_name', [AppExtensionRuntime::class, 'doSomething']),
                        new TwigFunction('function_name', [AppExtensionRuntime::class, 'doSomething']),
                        new TwigFunction('dynamic_name', $dynamicCallable),
                        new TwigFunction('outside_name', [OutsideRuntime::class, 'outside']),
                        new TwigFunction('unused_name', [AppExtensionRuntime::class, 'doSomething']),
                    ];
                }

                /** Builds an attributed value. */
                #[AsTwigFunction('attribute_function')]
                public function attributeFunction(string $value): string { return $value; }

                #[AsTwigFilter('attribute_filter')]
                public function attributeFilter(string $value): string { return $value; }
            }
            PHP;
        $runtimeUri = 'file:///workspace/src/Twig/AppExtensionRuntime.php';
        $runtimeText = <<<'PHP'
            <?php
            namespace App\Twig;

            final class AppExtensionRuntime
            {
                /** Formats the value for display. */
                public function doSomething(string $value): string
                {
                    return $value;
                }
            }
            PHP;
        $outsideUri = 'file:///outside/OutsideRuntime.php';
        $outsideText = <<<'PHP'
            <?php
            namespace App\Twig;

            final class OutsideRuntime
            {
                /** Must not be exposed. */
                public function outside(): void {}
            }
            PHP;
        $twigUri = 'file:///workspace/templates/page.html.twig';
        $twigText = <<<'TWIG'
            {{ function_name('value') }}
            {{ "}}" ~ function_name('value') }}
            {{ broken ??? }}
            {{ item|filter_name }}
            {{ {'nested': {}}|filter_name }}
            {{ attribute_function('value') }}
            {{ item|attribute_filter }}
            {{ dynamic_name() }}
            {{ outside_name() }}
            {{ object.function_name() }}
            {{ path('home') }}
            {# {{ function_name() }} #}
            Plain function_name() text
            TWIG;
        $environment = $this->providers([
            $extensionUri => $extensionText,
            $runtimeUri => $runtimeText,
            $outsideUri => $outsideText,
        ], [$twigUri => $twigText]);
        $provider = $environment['relationship'];
        $converter = $environment['converter'];
        $protocol = $environment['protocol'];

        $functionHover = [
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `function_name`\n\nCallable: `App\\Twig\\AppExtensionRuntime::doSomething`\n\n```php\npublic function doSomething(string \$value): string\n```\n\nFormats the value for display.",
            ],
        ];
        self::assertSame($functionHover, $provider->hover($this->params($twigUri, $twigText, 'function_name', $converter)));
        $delimiterOffset = strpos($twigText, 'function_name', \strlen('{{ function_name'));
        self::assertIsInt($delimiterOffset);
        self::assertSame($functionHover, $provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, $delimiterOffset)));
        $filterHover = [
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig filter: `filter_name`\n\nCallable: `App\\Twig\\AppExtensionRuntime::doSomething`\n\n```php\npublic function doSomething(string \$value): string\n```\n\nFormats the value for display.",
            ],
        ];
        self::assertSame($filterHover, $provider->hover($this->params($twigUri, $twigText, 'filter_name', $converter)));
        $nestedFilterOffset = strpos($twigText, 'filter_name', strpos($twigText, 'filter_name') + 1);
        self::assertIsInt($nestedFilterOffset);
        self::assertSame($filterHover, $provider->hover($this->params($twigUri, $twigText, 'filter_name', $converter, $nestedFilterOffset)));
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `attribute_function`\n\nCallable: `App\\Twig\\AppExtension::attributeFunction`\n\n```php\npublic function attributeFunction(string \$value): string\n```\n\nBuilds an attributed value.",
            ],
        ], $provider->hover($this->params($twigUri, $twigText, 'attribute_function', $converter)));
        $methodOffset = strpos($runtimeText, 'doSomething');
        self::assertIsInt($methodOffset);
        $methodLength = \strlen('doSomething');
        self::assertSame([
            $protocol->location(
                $runtimeUri,
                new Range(
                    $converter->toPosition($runtimeText, $methodOffset),
                    $converter->toPosition($runtimeText, $methodOffset + $methodLength),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'function_name', $converter)));
        $attributeMethodOffset = strpos($extensionText, 'attributeFunction');
        self::assertIsInt($attributeMethodOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $attributeMethodOffset),
                    $converter->toPosition($extensionText, $attributeMethodOffset + \strlen('attributeFunction')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'attribute_function', $converter)));

        $dynamicOffset = strpos($extensionText, 'dynamic_name');
        self::assertIsInt($dynamicOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $dynamicOffset),
                    $converter->toPosition($extensionText, $dynamicOffset + \strlen('dynamic_name')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'dynamic_name', $converter)));

        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "Twig function: `outside_name`\n\nCallable: `App\\Twig\\OutsideRuntime::outside`",
            ],
        ], $provider->hover($this->params($twigUri, $twigText, 'outside_name', $converter)));
        $outsideOffset = strpos($extensionText, 'outside_name');
        self::assertIsInt($outsideOffset);
        self::assertSame([
            $protocol->location(
                $extensionUri,
                new Range(
                    $converter->toPosition($extensionText, $outsideOffset),
                    $converter->toPosition($extensionText, $outsideOffset + \strlen('outside_name')),
                ),
            ),
        ], $provider->definition($this->params($twigUri, $twigText, 'outside_name', $converter)));

        $functionReferences = $provider->references($this->params($twigUri, $twigText, 'function_name', $converter));
        self::assertCount(2, $functionReferences ?? []);
        self::assertSame([$twigUri, $twigUri], array_column($functionReferences ?? [], 'uri'));
        self::assertCount(2, $provider->references($this->params($twigUri, $twigText, 'filter_name', $converter)) ?? []);
        self::assertCount(2, $provider->references($this->params($extensionUri, $extensionText, 'function_name', $converter)) ?? []);
        self::assertCount(2, $provider->references($this->params($extensionUri, $extensionText, 'filter_name', $converter)) ?? []);
        self::assertCount(1, $provider->references($this->params($extensionUri, $extensionText, 'attribute_function', $converter)) ?? []);
        self::assertCount(1, $provider->references($this->params($extensionUri, $extensionText, 'dynamic_name', $converter)) ?? []);
        self::assertSame([], $provider->references($this->params($extensionUri, $extensionText, 'unused_name', $converter)));
        /** @var list<array{range: array{start: array{line: int}}}> $methodReferences */
        $methodReferences = $provider->references($this->params($runtimeUri, $runtimeText, 'doSomething', $converter)) ?? [];
        self::assertSame([0, 1, 3, 4], array_map(static fn (array $location): int => $location['range']['start']['line'], $methodReferences));
        self::assertCount(4, $provider->references($this->params($runtimeUri, $runtimeText, 'doSomething', $converter, $methodOffset - intdiv($methodLength, 2))) ?? []);
        self::assertCount(4, $provider->references($this->params($runtimeUri, $runtimeText, 'doSomething', $converter, $methodOffset + $methodLength - intdiv($methodLength, 2))) ?? []);
        self::assertCount(1, $provider->references($this->params($extensionUri, $extensionText, 'attributeFunction', $converter)) ?? []);
        self::assertNull($provider->references($this->params($extensionUri, $extensionText, 'getFunctions', $converter)));

        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'path', $converter)));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strrpos($twigText, 'function_name'))));
        self::assertNull($provider->hover($this->params($twigUri, $twigText, 'function_name', $converter, strpos($twigText, 'Plain function_name') + \strlen('Plain '))));
    }
}
