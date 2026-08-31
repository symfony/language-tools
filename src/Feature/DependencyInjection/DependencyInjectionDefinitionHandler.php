<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionDefinitionHandler implements DefinitionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionProjectLookup $lookup,
    ) {
    }

    public function definition(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve(
            $request->document->uri,
            $request->document->languageId,
            $request->document->text,
            $request->position,
        );
        if (null === $symbol) {
            return null;
        }

        return array_map(
            fn (ServiceDeclaration|ParameterDeclaration|PhpClassDeclaration $target): array => $this->protocol->location($target->uri, $target->range),
            $this->lookup->definitionTargets($request->project, $symbol),
        );
    }
}
