<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\ReferencesProviderInterface;

final class DependencyInjectionReferencesHandler implements ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function references(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        $symbol = $this->symbolResolver->resolve(
            $document->uri(),
            $document->languageId(),
            $document->text(),
            $position,
        );
        if (null === $symbol) {
            return null;
        }

        $index = $this->sourceIndexes->forProject($project);
        $locations = array_map(
            fn (DependencyInjectionReference $reference): array => $this->location($reference->uri(), $reference->range()),
            $index->references($symbol->kind(), $symbol->name()),
        );
        $context = $params['context'] ?? null;
        if (\is_array($context) && true === ($context['includeDeclaration'] ?? null)) {
            $declarations = DependencyInjectionSymbolKind::Service === $symbol->kind()
                ? $index->serviceDeclarations($symbol->name())
                : $index->parameterDeclarations($symbol->name());
            foreach ($declarations as $declaration) {
                $locations[] = $this->location($declaration->uri(), $declaration->range());
            }
        }

        return $locations;
    }

    /**
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}
     */
    private function location(string $uri, Range $range): array
    {
        return [
            'uri' => $uri,
            'range' => [
                'start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()],
                'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()],
            ],
        ];
    }
}
