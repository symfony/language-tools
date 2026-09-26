<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class TwigComponentRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentResolver $components,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        $action = null === $request ? null : $this->components->resolveAction($request);
        if (null !== $action) {
            [$component, $componentAction] = $action;

            return $this->protocol->markdownHover(\sprintf('Live action: `%s#%s`', $component->name, $componentAction->name));
        }
        $request = $this->requests->positioned($params);
        $resolved = null === $request ? null : $this->components->resolveComponent($request);
        if (null === $resolved) {
            return null;
        }
        [$component] = $resolved;
        $details = [\sprintf('%s component: `%s`', $component->live ? 'Live' : 'Twig', $component->name)];
        if (null !== $component->className) {
            $details[] = \sprintf('Class: `%s`', $component->className);
        }
        if (null !== $component->template) {
            $details[] = \sprintf('Template: `%s`', $component->template);
        }
        if ([] !== $component->properties) {
            $details[] = \sprintf('Properties: `%s`', implode('`, `', $component->properties));
        }
        if ([] !== $component->actions) {
            $actions = [];
            foreach ($component->actions as $componentAction) {
                $actions[] = $componentAction->name;
            }
            $details[] = \sprintf('Actions: `%s`', implode('`, `', $actions));
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    public function definition(PositionedRequest $request): array
    {
        $action = $this->components->resolveAction($request);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = [];
            foreach ($this->indexes->forProject($project)->declarations($component->name) as $declaration) {
                foreach ($declaration->actions as $declarationAction) {
                    if ($componentAction->name === $declarationAction->name) {
                        $locations[] = $this->protocol->location($declaration->uri, $declarationAction->range);
                    }
                }
            }

            return $locations;
        }
        $resolved = $this->components->resolveComponent($request);
        if (null === $resolved) {
            return [];
        }
        [$component, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->declarations($component->name) as $declaration) {
            $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
        }
        if ([] === $locations && '' !== $component->uri) {
            // vendor components have no source declaration; open the class
            $locations[] = $this->protocol->location($component->uri, $component->range);
        }

        return $locations;
    }

    public function references(ReferencesRequest $request): array
    {
        $action = $this->components->resolveAction($request);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = $this->definition($request);
            foreach ($this->indexes->forProject($project)->actionReferences($component->name, $componentAction->name) as $reference) {
                $locations[] = $this->protocol->location($reference->uri, $reference->range);
            }

            return $locations;
        }
        $resolved = $this->components->resolveComponent($request);
        if (null === $resolved) {
            return [];
        }
        [$component, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->references($component->name) as $reference) {
            $locations[] = $this->protocol->location($reference->uri, $reference->range);
        }

        return $locations;
    }
}
