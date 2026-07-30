<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;

final class RouteDefinitionHandler
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>|null
     */
    public function definition(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($document->uri(), $document->text(), $position);
        if (null === $symbol) {
            return null;
        }

        return array_map(
            static fn (RouteDeclaration $declaration): array => [
                'uri' => $declaration->uri(),
                'range' => [
                    'start' => [
                        'line' => $declaration->range()->start()->line(),
                        'character' => $declaration->range()->start()->character(),
                    ],
                    'end' => [
                        'line' => $declaration->range()->end()->line(),
                        'character' => $declaration->range()->end()->character(),
                    ],
                ],
            ],
            $this->declarationIndexes->forProject($project)->find($symbol->name()),
        );
    }
}
