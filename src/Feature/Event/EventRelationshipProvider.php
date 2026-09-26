<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class EventRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly EventRelationshipResolver $relationships,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->relationships->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->relationships->eventHover($index, $symbol->name);
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        if (null !== $index->event($class->className) || [] !== $index->listenersForEvent($class->className)) {
            return $this->relationships->eventHover($index, $class->className);
        }
        $listeners = $index->listenersByClass($class->className);
        if ([] === $listeners) {
            return null;
        }
        $events = [];
        foreach ($listeners as $listener) {
            $events[$listener->event] = true;
        }

        return $this->protocol->markdownHover('Event listener: `'.$class->className.'`'."\n\n".'Events: `'.implode('`, `', array_keys($events)).'`');
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->relationships->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->relationships->eventDefinitionLocations($project, $index, $symbol->name);
        }
        if (!$class instanceof PhpClassDeclaration) {
            return [];
        }
        if (null !== $index->event($class->className) || [] !== $index->listenersForEvent($class->className)) {
            $classes = [];
            foreach ($index->listenersForEvent($class->className) as $listener) {
                $classes[] = $listener->className;
            }

            return $this->relationships->classLocations($project, $classes);
        }
        $eventClasses = [];
        foreach ($index->listenersByClass($class->className) as $listener) {
            if (null !== $eventClass = $index->event($listener->event)?->className) {
                $eventClasses[] = $eventClass;
            }
        }

        return $this->relationships->classLocations($project, $eventClasses);
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->relationships->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $class, $project] = $resolved;
        if ($symbol instanceof EventSourceSymbol) {
            return $this->protocol->locations($request->reported($this->relationships->sourceSymbols($project, $symbol->name)));
        }
        if (!$class instanceof PhpClassDeclaration) {
            return [];
        }
        $index = $this->indexes->forProject($project);
        if (null !== $index->event($class->className) || [] !== $index->listenersForEvent($class->className)) {
            return $this->protocol->locations($request->reported($this->relationships->sourceSymbols($project, $class->className)));
        }
        $symbols = [];
        foreach ($index->listenersByClass($class->className) as $listener) {
            array_push($symbols, ...$this->relationships->sourceSymbols($project, $listener->event));
        }

        return $this->protocol->locations($request->reported($symbols));
    }
}
