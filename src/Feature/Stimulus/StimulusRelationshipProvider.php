<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->stimulus->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $controller = $this->indexes->forProject($project)->controller($reference->controller());
        $declarations = $this->sourceIndexes->forProject($project)->declarations($reference->controller());
        if (null === $controller && [] === $declarations) {
            return null;
        }
        if (null !== $reference->kind() && null !== $reference->member()) {
            if (!\in_array($reference->member(), $this->stimulus->members($project, $reference->controller(), $reference->kind()), true)) {
                return null;
            }

            return $this->protocol->markdownHover(\sprintf('Stimulus %s: `%s#%s`', $reference->kind()->value, $reference->controller(), $reference->member()));
        }
        $details = [\sprintf('Stimulus controller: `%s`', $reference->controller())];
        $source = $controller?->sourcePath() ?? (isset($declarations[0]) ? $this->uriConverter->convert($declarations[0]->uri()) : null);
        if (null !== $source) {
            $details[] = \sprintf('Source: `%s`', $source);
        }
        $details[] = 'Lazy: '.($controller?->isLazy() || ($declarations[0] ?? null)?->isLazy() ? 'yes' : 'no');
        if (null !== $controller) {
            $details[] = 'Vendor: '.($controller->isVendor() ? 'yes' : 'no');
        }
        foreach (StimulusMemberKind::cases() as $kind) {
            $members = $this->stimulus->members($project, $reference->controller(), $kind);
            if ([] !== $members) {
                $details[] = ucfirst($kind->value).'s: `'.implode('`, `', $members).'`';
            }
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->stimulus->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $locations = $this->stimulus->declarationLocations($project, $reference);
        if ([] !== $locations) {
            return $locations;
        }
        if (null !== $reference->kind() && (null === $reference->member() || !\in_array($reference->member(), $this->stimulus->members($project, $reference->controller(), $reference->kind()), true))) {
            return [];
        }
        $controller = $this->indexes->forProject($project)->controller($reference->controller());

        return null === $controller ? [] : [['uri' => $this->uriConverter->toUri($controller->sourcePath()), 'range' => $this->protocol->zeroRange()]];
    }

    public function references(array $params): ?array
    {
        $resolved = $this->stimulus->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $locations = $this->stimulus->declarationLocations($project, $reference);
        foreach ($this->sourceIndexes->forProject($project)->references($reference->controller(), $reference->kind(), $reference->member()) as $candidate) {
            $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
        }

        return $locations;
    }
}
