<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly EventRelationshipResolver $relationships,
    ) {
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $lenses = [];
        foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
            $listeners = $index->listenersForEvent($class->className());
            if (null !== $index->event($class->className()) || [] !== $listeners) {
                $related = [];
                foreach ($listeners as $listener) {
                    $related[$listener->className()] = true;
                }
                $classes = array_keys($related);
                $count = \count($classes);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('%d event listener%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->relationships->classLocations($request->project, $classes));
                continue;
            }
            $handled = $index->listenersByClass($class->className());
            if ([] === $handled) {
                continue;
            }
            $events = [];
            foreach ($handled as $listener) {
                $events[$listener->event()] = true;
            }
            $locations = [];
            foreach (array_keys($events) as $event) {
                if (null !== $eventClass = $index->event($event)?->className()) {
                    array_push($locations, ...$this->relationships->classLocations($request->project, [$eventClass]));
                }
            }
            $count = \count($events);
            $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('Listens to %d event%s', $count, 1 === $count ? '' : 's'), $class->uri(), $locations);
        }

        return $lenses;
    }
}
