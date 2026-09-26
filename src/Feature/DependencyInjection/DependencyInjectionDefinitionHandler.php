<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class DependencyInjectionDefinitionHandler implements DefinitionProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionProjectLookup $lookup,
    ) {
    }

    public function definition(PositionedRequest $request): array
    {
        $symbol = $this->symbolResolver->resolve($request);
        if (null === $symbol) {
            return [];
        }

        return array_map(
            fn (ServiceDeclaration|ParameterDeclaration|PhpClassDeclaration $target): array => $this->protocol->location($target->uri, $target->range),
            $this->lookup->definitionTargets($request->project, $symbol),
        );
    }
}
