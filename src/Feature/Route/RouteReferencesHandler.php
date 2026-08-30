<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteReferencesHandler implements ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
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
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'twig', 'yaml'], true)) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($request->project, $request->document->uri, $request->document->text, $request->position);
        if (null === $symbol) {
            return null;
        }

        $locations = array_map(
            fn (RouteReferenceLocation $reference): array => $this->protocol->location($reference->uri(), $reference->range()),
            $this->referenceIndexes->forProject($request->project)->find($symbol->name()),
        );
        $context = $params['context'] ?? null;
        if (\is_array($context) && true === ($context['includeDeclaration'] ?? null)) {
            foreach ($this->declarationIndexes->forProject($request->project)->find($symbol->name()) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
            }
        }

        return $locations;
    }
}
