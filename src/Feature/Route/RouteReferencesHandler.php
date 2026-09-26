<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class RouteReferencesHandler implements ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function references(ReferencesRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'twig', 'yaml'], true)) {
            return [];
        }

        $symbol = $this->symbolResolver->resolve($request);
        if (null === $symbol) {
            return [];
        }

        $index = $this->sourceIndexes->forProject($request->project);

        return $this->protocol->locations([
            ...$index->references($symbol->name),
            ...($request->includeDeclaration ? $index->declarations($symbol->name) : []),
        ]);
    }
}
