<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class EnvironmentRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly EnvironmentIndexRegistry $indexes,
        private readonly EnvironmentSymbolResolver $symbols,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->symbols->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $details = [\sprintf('Environment variable: `%s`', $reference->name)];
        if ([] !== $reference->processors) {
            $details[] = \sprintf('Processors: `%s`', implode('`, `', $reference->processors));
            foreach ($reference->processors as $processor) {
                if (isset($index->processors()[$processor])) {
                    $details[] = \sprintf('Expected type: `%s`', $index->processors()[$processor]);
                    break;
                }
            }
        }
        foreach ($index->declarations($reference->name) as $declaration) {
            $details[] = \sprintf('Declared in: `%s`', $declaration->uri);
            $details[] = 'Default present: '.($declaration->hasDefault ? 'yes' : 'no');
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->symbols->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$reference, $project] = $resolved;

        return $this->declarationLocations($project, $reference->name);
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->symbols->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$reference, $project] = $resolved;

        $locations = array_map(fn (EnvironmentReference $item): array => $this->protocol->location($item->uri, $item->range), $this->indexes->forProject($project)->references($reference->name));

        return $request->includeDeclaration ? [...$locations, ...$this->declarationLocations($project, $reference->name)] : $locations;
    }

    /** @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}> */
    private function declarationLocations(Project $project, string $name): array
    {
        return array_map(fn (EnvironmentDeclaration $declaration): array => $this->protocol->location($declaration->uri, $declaration->range), $this->indexes->forProject($project)->declarations($name));
    }
}
