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
            );
        }

        return new TwigCallableSourceFacts($uri, $declarations);
    }

    private function kind(PhpObjectCreation $creation): ?TwigCallableKind
    {
        return match ($creation->className()) {
            'Twig\\TwigFilter' => TwigCallableKind::Filter,
            'Twig\\TwigFunction' => TwigCallableKind::Function,
            default => null,
        };
    }
}
