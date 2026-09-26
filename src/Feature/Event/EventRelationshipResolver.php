<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassLocationResolver;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class EventRelationshipResolver
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly EventSourceIndexRegistry $sourceIndexes,
        private readonly EventExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly PhpClassLocationResolver $classLocations,
    ) {
    }

    /** @return array{EventSourceSymbol|null, PhpClassDeclaration|null, Project}|null */
    public function resolve(PositionedRequest $request): ?array
    {
        $symbol = $this->positionedSymbols->resolve($request->source, $request->position, $this->extractor->extract($request->source)->symbols);
        if ($symbol instanceof EventSourceSymbol) {
            return [$symbol, null, $request->project];
        }
        $class = 'php' === $request->document->languageId
            ? $this->positionedSymbols->resolve($request->source, $request->position, $this->classExtractor->extract($request->document->uri, $request->document->text))
            : null;

        return $class instanceof PhpClassDeclaration ? [null, $class, $request->project] : null;
    }

    /** @return array<array-key, mixed>|null */
    public function eventHover(EventIndex $index, string $name): ?array
    {
        $event = $index->event($name);
        $listeners = $index->listenersForEvent($name);
        if (null === $event && [] === $listeners) {
            return null;
        }
        $lines = ['Symfony event: `'.$name.'`'];
        if (null !== $event?->className) {
            $lines[] = '';
            $lines[] = 'Class: `'.$event->className.'`';
        }
        $listenerNames = [];
        foreach ($listeners as $listener) {
            $listenerNames[] = $listener->className.'::'.$listener->method.' ('.$listener->priority.')';
        }
        $lines[] = '';
        $lines[] = 'Listeners: '.([] === $listenerNames ? 'none' : '`'.implode('`, `', $listenerNames).'`');

        return $this->protocol->markdownHover(implode("\n", $lines));
    }

    /** @return list<array<array-key, mixed>> */
    public function eventDefinitionLocations(Project $project, EventIndex $index, string $name): array
    {
        $classes = [];
        if (null !== $eventClass = $index->event($name)?->className) {
            $classes[$eventClass] = true;
        }
        foreach ($index->listenersForEvent($name) as $listener) {
            $classes[$listener->className] = true;
        }

        return $this->classLocations->locations($project, array_keys($classes));
    }

    /** @return list<EventSourceSymbol> */
    public function sourceSymbols(Project $project, string $name): array
    {
        return $this->sourceIndexes->forProject($project)->symbols($name);
    }
}
