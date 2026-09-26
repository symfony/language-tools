<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassLocationResolver;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly PhpClassLocationResolver $classLocations,
    ) {
    }

    public function codeLenses(DocumentRequest $request): array
    {
        if ('php' !== $request->document->languageId) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        $lenses = [];
        foreach ($this->classExtractor->extract($request->document->uri, $request->document->text) as $class) {
            $listeners = $index->listenersForEvent($class->className);
            if (null !== $index->event($class->className) || [] !== $listeners) {
                $related = [];
                foreach ($listeners as $listener) {
                    $related[$listener->className] = true;
                }
                $classes = array_keys($related);
                $count = \count($classes);
                $lenses[] = $this->protocol->referenceLens($class->range, \sprintf('%d event listener%s', $count, 1 === $count ? '' : 's'), $class->uri, $this->classLocations->locations($request->project, $classes));
                continue;
            }
            $handled = $index->listenersByClass($class->className);
            if ([] === $handled) {
                continue;
            }
            $events = [];
            foreach ($handled as $listener) {
                $events[$listener->event] = true;
            }
            $locations = [];
            foreach (array_keys($events) as $event) {
                if (null !== $eventClass = $index->event($event)?->className) {
                    array_push($locations, ...$this->classLocations->locations($request->project, [$eventClass]));
                }
            }
            $count = \count($events);
            $lenses[] = $this->protocol->referenceLens($class->range, \sprintf('Listens to %d event%s', $count, 1 === $count ? '' : 's'), $class->uri, $locations);
        }

        return $lenses;
    }
}
