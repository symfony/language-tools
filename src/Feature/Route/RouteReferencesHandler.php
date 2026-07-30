<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;

final class RouteReferencesHandler
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteReferenceIndexRegistry $referenceIndexes,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>|null
     */
    public function references(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'twig', 'yaml'], true)) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($document->uri(), $document->text(), $position);
        if (null === $symbol) {
            return null;
        }

        $locations = array_map(
            static fn (RouteReferenceLocation $reference): array => self::location(
                $reference->uri(),
                $reference->range(),
            ),
            $this->referenceIndexes->forProject($project)->find($symbol->name()),
        );
        $context = $params['context'] ?? null;
        if (\is_array($context) && true === ($context['includeDeclaration'] ?? null)) {
            foreach ($this->declarationIndexes->forProject($project)->find($symbol->name()) as $declaration) {
                $locations[] = self::location($declaration->uri(), $declaration->range());
            }
        }

        return $locations;
    }

    /**
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}
     */
    private static function location(string $uri, Range $range): array
    {
        return [
            'uri' => $uri,
            'range' => [
                'start' => [
                    'line' => $range->start()->line(),
                    'character' => $range->start()->character(),
                ],
                'end' => [
                    'line' => $range->end()->line(),
                    'character' => $range->end()->character(),
                ],
            ],
        ];
    }
}
