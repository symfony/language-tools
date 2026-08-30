<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteDefinitionHandler implements DefinitionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
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
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($request->project, $request->document->uri, $request->document->text, $request->position);
        if (null === $symbol) {
            return null;
        }

        return array_map(
            fn (RouteDeclaration $declaration): array => $this->protocol->location($declaration->uri(), $declaration->range()),
            $this->declarationIndexes->forProject($request->project)->find($symbol->name()),
        );
    }
}
