<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class TwigCallableDeclarationExtractorTest extends TestCase
{
    public function testExtractsCustomTwigFunctionsAndFilters(): void
    {
        $extractor = $this->declarationExtractor($converter = new PositionConverter());
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use App\Twig\AppExtensionRuntime as Runtime;
            use Twig\Attribute\AsTwigFilter as FilterAttribute;
            use Twig\Attribute\AsTwigFunction;
            use Twig\Environment;
            use Twig\TwigFilter as Filter;
            use Twig\TwigFunction as FunctionDefinition;

            final class AppExtension
            {
                private const OPTIONS = ['needs_environment' => true];

                public function getFilters(): array
                {
                    return [
                        new Filter('filter_name', [Runtime::class, 'doSomething'], ['needs_context' => true]),
                        new Filter(name: 'named_filter', callable: $this->localFilter(...)),
                    ];
                }

                public function getFunctions(): array
                {
                    return [
                        new FunctionDefinition('function_name', [Runtime::class, 'doSomething'], ['needs_charset' => true, 'needs_environment' => true, 'needs_is_sandboxed' => true, 'is_variadic' => true]),
                        new FunctionDefinition('constant_options', [Runtime::class, 'doSomething'], self::OPTIONS),
                        new FunctionDefinition("f\x6fo", [Runtime::class, 'doSomething']),
                        new FunctionDefinition('dynamic_name', $dynamicCallable),
                        new OtherDefinition('ignored_name', [Runtime::class, 'ignored']),
                    ];
                }

                public function configure(): void
                {
                    new FunctionDefinition('ignored_direct_function', [Runtime::class, 'ignored']);
                    new Filter('ignored_direct_filter', [Runtime::class, 'ignored']);
                }

                #[FilterAttribute('attribute_filter', needsContext: true, needsIsSandboxed: true)]
                public function attributeFilter(Environment $environment, array $context, bool $isSandboxed, string $value): string { return $value; }

                #[AsTwigFunction(name: 'attribute_function', needsCharset: true)]
                public function attributeFunction(string $charset, string $value, mixed ...$options): string { return $value; }

                #[AsTwigFunction('auto_environment')]
                public function autoEnvironment(Environment $environment, string $value): string { return $value; }

                #[AsTwigFunction('legacy_safe', null, null, null, ['html'])]
                public function legacySafe(string $value): string { return $value; }

                #[AsTwigFunction("f\x6fo")]
                public function escapedAttribute(): string { return ''; }

                #[AsTwigFunction(self::DYNAMIC_NAME)]
                public function dynamicAttribute(): string { return ''; }

                #[AsTwigFunction('private_attribute')]
                private function privateAttribute(): string { return ''; }

                private function localFilter(string $value): string { return $value; }
            }

            trait SharedExtension
            {
                #[AsTwigFunction('trait_function')]
                public function traitFunction(string $value): string { return $value; }
            }
            PHP;

        $declarations = $extractor->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations;

        self::assertSame([
            [TwigCallableKind::Filter, 'filter_name', 'App\Twig\AppExtensionRuntime', 'doSomething', false, false, true, false, false, true],
            [TwigCallableKind::Filter, 'named_filter', 'App\Twig\AppExtension', 'localFilter', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'function_name', 'App\Twig\AppExtensionRuntime', 'doSomething', true, true, false, true, true, true],
            [TwigCallableKind::Function, 'constant_options', 'App\Twig\AppExtensionRuntime', 'doSomething', false, false, false, false, false, false],
            [TwigCallableKind::Function, 'foo', 'App\Twig\AppExtensionRuntime', 'doSomething', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'dynamic_name', null, null, false, false, false, false, false, true],
            [TwigCallableKind::Filter, 'attribute_filter', 'App\Twig\AppExtension', 'attributeFilter', false, true, true, true, false, true],
            [TwigCallableKind::Function, 'attribute_function', 'App\Twig\AppExtension', 'attributeFunction', true, false, false, false, true, true],
            [TwigCallableKind::Function, 'auto_environment', 'App\Twig\AppExtension', 'autoEnvironment', false, true, false, false, false, true],
            [TwigCallableKind::Function, 'legacy_safe', 'App\Twig\AppExtension', 'legacySafe', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'foo', 'App\Twig\AppExtension', 'escapedAttribute', false, false, false, false, false, true],
            [TwigCallableKind::Function, 'trait_function', 'App\Twig\SharedExtension', 'traitFunction', false, false, false, false, false, true],
        ], array_map(static fn ($declaration): array => [
            $declaration->kind,
            $declaration->name,
            $declaration->className,
            $declaration->method,
            $declaration->needsCharset,
            $declaration->needsEnvironment,
            $declaration->needsContext,
            $declaration->needsIsSandboxed,
            $declaration->variadic,
            $declaration->optionsKnown,
        ], $declarations));
        $functionOffset = strpos($source, 'function_name');
        self::assertIsInt($functionOffset);
        self::assertSame($converter->toPosition($source, $functionOffset)->line, $declarations[2]->range->start->line);
        self::assertSame($converter->toPosition($source, $functionOffset)->character, $declarations[2]->range->start->character);
    }

    public function testIgnoresUnknownNamedFirstArgumentsAsCallableNames(): void
    {
        $source = <<<'PHP'
            <?php
            use Twig\Attribute\AsTwigFunction;
            use Twig\TwigFunction;

            final class AppExtension
            {
                public function getFunctions(): array
                {
                    return [new TwigFunction(unknown: 'not_a_function')];
                }

                #[AsTwigFunction(unknown: 'not_an_attribute')]
                public function attributed(): string { return ''; }
            }
            PHP;

        $declarations = $this->declarationExtractor(new PositionConverter())->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations;

        self::assertSame([], $declarations);
    }

    public function testIgnoresIncompleteAttributedDeclarations(): void
    {
        $source = <<<'PHP'
            <?php
            use Twig\Attribute\AsTwigFunction;

            final class AppExtension
            {
                #[AsTwigFunction('complete')]
                public function complete(): string { return ''; }

                #[AsTwigFunction(
                public function incomplete(): string { return ''; }
            }
            PHP;

        $declarations = $this->declarationExtractor(new PositionConverter())->extract('file:///workspace/src/Twig/AppExtension.php', $source)->declarations;

        self::assertSame(['complete'], array_map(static fn ($declaration): string => $declaration->name, $declarations));
    }

    private function declarationExtractor(PositionConverter $converter): TwigCallableDeclarationExtractor
    {
        return new TwigCallableDeclarationExtractor($converter, new TolerantPhpParser(new Parser()));
    }
}
