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
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly ServiceIndexRegistry $serviceIndexes,
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

        $index = $this->sourceIndexes->forProject($request->project);
        $locations = [];
        if (DependencyInjectionSymbolKind::Parameter === $symbol->kind) {
            foreach ($index->parameterDeclarations($symbol->name) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
            }

            return $locations;
        }

        $serviceNames = [$symbol->name];
        $runtimeService = $this->serviceIndexes->forProject($request->project)->get($symbol->name);
        if (null !== $runtimeService?->alias) {
            $serviceNames[] = $runtimeService->alias;
        }
        foreach ($index->serviceDeclarations($symbol->name) as $declaration) {
            $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
            if (null !== $declaration->alias) {
                $serviceNames[] = $declaration->alias;
            }
        }
        foreach ($index->decoratorsOf($symbol->name) as $declaration) {
            $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
        }

        $classNames = [];
        foreach (array_values(array_unique($serviceNames)) as $serviceName) {
            $service = $this->serviceIndexes->forProject($request->project)->get($serviceName);
            if (null !== $service?->className) {
                $classNames[] = $service->className;
            }
            foreach ($index->serviceDeclarations($serviceName) as $declaration) {
                if (null !== $declaration->className) {
                    $classNames[] = $declaration->className;
                }
            }
        }
        foreach (array_values(array_unique($classNames)) as $className) {
            foreach ($index->classDeclarations($className) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
            }
        }

        return $this->unique($locations);
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
