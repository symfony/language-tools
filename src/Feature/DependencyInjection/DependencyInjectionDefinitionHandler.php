<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DefinitionProviderInterface;

final class DependencyInjectionDefinitionHandler implements DefinitionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly ServiceIndexRegistry $serviceIndexes,
    ) {
    }

    public function definition(array $params): ?array
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
        $locations = [];
        if (DependencyInjectionSymbolKind::Parameter === $symbol->kind()) {
            foreach ($index->parameterDeclarations($symbol->name()) as $declaration) {
                $locations[] = $this->location($declaration->uri(), $declaration->range());
            }

            return $locations;
        }

        $serviceNames = [$symbol->name()];
        $runtimeService = $this->serviceIndexes->forProject($project)->get($symbol->name());
        if (null !== $runtimeService?->alias()) {
            $serviceNames[] = $runtimeService->alias();
        }
        foreach ($index->serviceDeclarations($symbol->name()) as $declaration) {
            $locations[] = $this->location($declaration->uri(), $declaration->range());
            if (null !== $declaration->alias()) {
                $serviceNames[] = $declaration->alias();
            }
        }
        foreach ($index->decoratorsOf($symbol->name()) as $declaration) {
            $locations[] = $this->location($declaration->uri(), $declaration->range());
        }

        $classNames = [];
        foreach (array_values(array_unique($serviceNames)) as $serviceName) {
            $service = $this->serviceIndexes->forProject($project)->get($serviceName);
            if (null !== $service?->className()) {
                $classNames[] = $service->className();
            }
            foreach ($index->serviceDeclarations($serviceName) as $declaration) {
                if (null !== $declaration->className()) {
                    $classNames[] = $declaration->className();
                }
            }
        }
        foreach (array_values(array_unique($classNames)) as $className) {
            foreach ($index->classDeclarations($className) as $declaration) {
                $locations[] = $this->location($declaration->uri(), $declaration->range());
            }
        }

        return $this->unique($locations);
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

    /**
     * @param list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}> $locations
     *
     * @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>
     */
    private function unique(array $locations): array
    {
        $unique = [];
        foreach ($locations as $location) {
            $key = $location['uri'].'\0'.json_encode($location['range'], \JSON_THROW_ON_ERROR);
            $unique[$key] = $location;
        }

        return array_values($unique);
    }
}
