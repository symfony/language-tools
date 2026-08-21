<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly EventRelationshipResolver $relationships,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->relationships->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->relationships->eventHover($index, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            return $this->relationships->eventHover($index, $class->className());
        }
        $listeners = $index->listenersByClass($class->className());
        if ([] === $listeners) {
            return null;
        }
        $events = [];
        foreach ($listeners as $listener) {
            $events[$listener->event()] = true;
        }

        return $this->protocol->markdownHover('Event listener: `'.$class->className().'`'."\n\n".'Events: `'.implode('`, `', array_keys($events)).'`');
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->relationships->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->relationships->eventDefinitionLocations($project, $index, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            $classes = [];
            foreach ($index->listenersForEvent($class->className()) as $listener) {
                $classes[] = $listener->className();
            }

            return $this->relationships->classLocations($project, $classes);
        }
        $eventClasses = [];
        foreach ($index->listenersByClass($class->className()) as $listener) {
            if (null !== $eventClass = $index->event($listener->event())?->className()) {
                $eventClasses[] = $eventClass;
            }
        }

        return $this->relationships->classLocations($project, $eventClasses);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->relationships->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        if ($symbol instanceof EventSourceSymbol) {
            return $this->relationships->sourceLocations($project, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            return $this->relationships->sourceLocations($project, $class->className());
        }
        $locations = [];
        foreach ($index->listenersByClass($class->className()) as $listener) {
            array_push($locations, ...$this->relationships->sourceLocations($project, $listener->event()));
        }

        return $this->relationships->uniqueLocations($locations);
    }
}
