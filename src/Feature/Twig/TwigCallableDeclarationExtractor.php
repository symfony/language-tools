<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class TwigCallableDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    public function extract(string $uri, string $text): TwigCallableSourceFacts
    {
        $declarations = [];
        foreach ($this->parser->parse($text)->objectCreations() as $creation) {
            $kind = $this->kind($creation);
            if (null === $kind) {
                continue;
            }
            $name = ($creation->argument('name') ?? $creation->argument(0))?->stringLiteral();
            if (null === $name || '' === $name->value()) {
                continue;
            }
            $callable = ($creation->argument('callable') ?? $creation->argument(1))?->callable();
            $options = $this->options($creation);
            $declarations[] = new TwigCallableDeclaration(
                $kind,
                $name->value(),
                $uri,
                new Range(
                    $this->converter->toPosition($text, $name->startOffset()),
                    $this->converter->toPosition($text, $name->endOffset()),
                ),
                $callable?->className(),
                $callable?->method(),
                $options['needsEnvironment'],
                $options['needsContext'],
                $options['variadic'],
                $options['known'],
            );
        }

        return new TwigCallableSourceFacts($uri, $declarations);
    }

    /** @return array{needsEnvironment: bool, needsContext: bool, variadic: bool, known: bool} */
    private function options(PhpObjectCreation $creation): array
    {
        $argument = $creation->argument('options') ?? $creation->argument(2);
        if (null === $argument) {
            return ['needsEnvironment' => false, 'needsContext' => false, 'variadic' => false, 'known' => true];
        }
        $expression = trim((string) $argument->expression());
        $known = str_starts_with($expression, '[') || 1 === preg_match('/^array\s*\(/i', $expression);

        return [
            'needsEnvironment' => $this->option($expression, 'needs_environment'),
            'needsContext' => $this->option($expression, 'needs_context'),
            'variadic' => $this->option($expression, 'is_variadic'),
            'known' => $known,
        ];
    }

    private function option(string $options, string $name): bool
    {
        return 1 === preg_match('/([\'\"])'.preg_quote($name, '/').'\\1\s*=>\s*true\b/i', $options);
    }

    private function kind(PhpObjectCreation $creation): ?TwigCallableKind
    {
        return match ([$creation->className(), $creation->enclosingMethod()]) {
            ['Twig\\TwigFilter', 'getFilters'] => TwigCallableKind::Filter,
            ['Twig\\TwigFunction', 'getFunctions'] => TwigCallableKind::Function,
            default => null,
        };
    }
}
