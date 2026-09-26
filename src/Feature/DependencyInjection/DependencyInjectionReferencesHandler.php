<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class DependencyInjectionReferencesHandler implements ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function references(ReferencesRequest $request): array
    {
        $symbol = $this->symbolResolver->resolve($request);
        if (null === $symbol) {
            return [];
        }

        $index = $this->sourceIndexes->forProject($request->project);
        $declarations = DependencyInjectionSymbolKind::Service === $symbol->kind
            ? $index->serviceDeclarations($symbol->name)
            : $index->parameterDeclarations($symbol->name);

        return $this->protocol->locations([
            ...$index->references($symbol->kind, $symbol->name),
            ...($request->includeDeclaration ? $declarations : []),
        ]);
    }
}
