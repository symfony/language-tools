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
                $this->option($creation, 'needs_environment'),
                $this->option($creation, 'needs_context'),
                $this->option($creation, 'is_variadic'),
            );
        }

        return new TwigCallableSourceFacts($uri, $declarations);
    }

    private function option(PhpObjectCreation $creation, string $name): bool
    {
        $options = ($creation->argument('options') ?? $creation->argument(2))?->expression();

        return null !== $options && 1 === preg_match('/([\'\"])'.preg_quote($name, '/').'\\1\s*=>\s*true\b/i', $options);
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
