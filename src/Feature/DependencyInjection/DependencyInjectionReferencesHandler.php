<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionReferencesHandler implements ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function references(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve(
            SourceDocument::fromDocument($request->document),
            $request->position,
        );
        if (null === $symbol) {
            return null;
        }

        $index = $this->sourceIndexes->forProject($request->project);
        $locations = array_map(
            fn (DependencyInjectionReference $reference): array => $this->protocol->location($reference->uri, $reference->range),
            $index->references($symbol->kind, $symbol->name),
        );
        $context = $params['context'] ?? null;
        if (\is_array($context) && true === ($context['includeDeclaration'] ?? null)) {
            $declarations = DependencyInjectionSymbolKind::Service === $symbol->kind
                ? $index->serviceDeclarations($symbol->name)
                : $index->parameterDeclarations($symbol->name);
            foreach ($declarations as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
            }
        }

        return $locations;
    }
}
