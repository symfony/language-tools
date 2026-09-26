<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class RouteDefinitionHandler implements DefinitionProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function definition(PositionedRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return [];
        }

        $symbol = $this->symbolResolver->resolve($request->project, $request->source, $request->position);

        return null === $symbol ? [] : $this->protocol->locations($this->sourceIndexes->forProject($request->project)->declarations($symbol->name));
    }
}
