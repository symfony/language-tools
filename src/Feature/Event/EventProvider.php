<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class EventProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly EventIndexRegistry $indexes,
        private readonly EventSourceIndexRegistry $sourceIndexes,
        private readonly EventExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $prefix = $this->extractor->completionPrefix($document->languageId(), $document->text(), $offset);
        if (null === $prefix) {
            return null;
        }
        $items = [];
        foreach ($this->indexes->forProject($project)->events() as $event) {
            if (str_starts_with($event->name(), $prefix)) {
                $items[] = $this->completion($event->name(), $document->text(), $offset - \strlen($prefix), $position);
            }
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->eventHover($index, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            return $this->eventHover($index, $class->className());
        }
        $listeners = $index->listenersByClass($class->className());
        if ([] === $listeners) {
            return null;
        }
        $events = array_values(array_unique(array_map(static fn (EventListener $listener): string => $listener->event(), $listeners)));

        return ['contents' => ['kind' => 'markdown', 'value' => 'Event listener: `'.$class->className().'`'."\n\n".'Events: `'.implode('`, `', $events).'`']];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if ($symbol instanceof EventSourceSymbol) {
            return $this->eventDefinitionLocations($project, $index, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            return $this->classLocations($project, array_map(static fn (EventListener $listener): string => $listener->className(), $index->listenersForEvent($class->className())));
        }
        $eventClasses = [];
        foreach ($index->listenersByClass($class->className()) as $listener) {
            if (null !== $eventClass = $index->event($listener->event())?->className()) {
                $eventClasses[] = $eventClass;
            }
        }

        return $this->classLocations($project, $eventClasses);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        if ($symbol instanceof EventSourceSymbol) {
            return $this->sourceLocations($project, $symbol->name());
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        if (null !== $index->event($class->className()) || [] !== $index->listenersForEvent($class->className())) {
            return $this->sourceLocations($project, $class->className());
        }
        $locations = [];
        foreach ($index->listenersByClass($class->className()) as $listener) {
            array_push($locations, ...$this->sourceLocations($project, $listener->event()));
        }

        return $this->uniqueLocations($locations);
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        if (null === $document || 'php' !== $document->languageId() || null === $this->projects->forDocumentUri($document->uri())) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->invalidListenerMethods() as $listener) {
            $diagnostics[] = [
                'range' => $this->range($listener->range()),
                'severity' => 1,
                'source' => 'symfony',
                'code' => 'event.invalid_listener_method',
                'message' => \sprintf('Event listener method "%s::%s" does not exist.', $listener->className(), $listener->method()),
            ];
        }

        return $diagnostics;
    }

    public function codeLenses(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        if (null === $document || 'php' !== $document->languageId()) {
            return null;
        }
        $project = $this->projects->forDocumentUri($document->uri());
        if (null === $project) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $lenses = [];
        foreach ($this->classExtractor->extract($document->uri(), $document->text()) as $class) {
            $listeners = $index->listenersForEvent($class->className());
            if (null !== $index->event($class->className()) || [] !== $listeners) {
                $related = array_values(array_unique(array_map(static fn (EventListener $listener): string => $listener->className(), $listeners)));
                $count = \count($related);
                $lenses[] = $this->lens($class, \sprintf('%d event listener%s', $count, 1 === $count ? '' : 's'), $this->classLocations($project, $related));
                continue;
            }
            $handled = $index->listenersByClass($class->className());
            if ([] !== $handled) {
                $events = array_values(array_unique(array_map(static fn (EventListener $listener): string => $listener->event(), $handled)));
                $locations = [];
                foreach ($events as $event) {
                    if (null !== $eventClass = $index->event($event)?->className()) {
                        array_push($locations, ...$this->classLocations($project, [$eventClass]));
                    }
                }
                $count = \count($events);
                $lenses[] = $this->lens($class, \sprintf('Listens to %d event%s', $count, 1 === $count ? '' : 's'), $locations);
            }
        }

        return $lenses;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{EventSourceSymbol|null, PhpClassDeclaration|null, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->symbols() as $symbol) {
            if ($this->contains($document->text(), $symbol->range(), $offset)) {
                return [$symbol, null, $project];
            }
        }
        if ('php' === $document->languageId()) {
            foreach ($this->classExtractor->extract($document->uri(), $document->text()) as $class) {
                if ($this->contains($document->text(), $class->range(), $offset)) {
                    return [null, $class, $project];
                }
            }
        }

        return null;
    }

    /** @return array<array-key, mixed>|null */
    private function eventHover(EventIndex $index, string $name): ?array
    {
        $event = $index->event($name);
        $listeners = $index->listenersForEvent($name);
        if (null === $event && [] === $listeners) {
            return null;
        }
        $lines = ['Symfony event: `'.$name.'`'];
        if (null !== $event?->className()) {
            $lines[] = '';
            $lines[] = 'Class: `'.$event->className().'`';
        }
        $lines[] = '';
        $lines[] = 'Listeners: '.([] === $listeners ? 'none' : '`'.implode('`, `', array_map(static fn (EventListener $listener): string => $listener->className().'::'.$listener->method().' ('.$listener->priority().')', $listeners)).'`');

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n", $lines)]];
    }

    /** @return list<array<array-key, mixed>> */
    private function eventDefinitionLocations(Project $project, EventIndex $index, string $name): array
    {
        $classes = [];
        if (null !== $eventClass = $index->event($name)?->className()) {
            $classes[] = $eventClass;
        }
        foreach ($index->listenersForEvent($name) as $listener) {
            $classes[] = $listener->className();
        }

        return $this->classLocations($project, array_values(array_unique($classes)));
    }

    /** @return list<array<array-key, mixed>> */
    private function sourceLocations(Project $project, string $name): array
    {
        return array_map(fn (EventSourceSymbol $symbol): array => ['uri' => $symbol->uri(), 'range' => $this->range($symbol->range())], $this->sourceIndexes->forProject($project)->symbols($name));
    }

    /**
     * @param list<string> $classNames
     *
     * @return list<array<array-key, mixed>>
     */
    private function classLocations(Project $project, array $classNames): array
    {
        $locations = [];
        foreach (array_values(array_unique($classNames)) as $className) {
            foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $declaration) {
                $locations[] = ['uri' => $declaration->uri(), 'range' => $this->range($declaration->range())];
            }
        }

        return $this->uniqueLocations($locations);
    }

    /**
     * @param list<array<array-key, mixed>> $locations
     *
     * @return list<array<array-key, mixed>>
     */
    private function uniqueLocations(array $locations): array
    {
        $unique = [];
        foreach ($locations as $location) {
            $unique[json_encode($location, \JSON_THROW_ON_ERROR)] = $location;
        }

        return array_values($unique);
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }

    /** @return array<array-key, mixed> */
    private function completion(string $name, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $name, 'kind' => 12, 'textEdit' => ['range' => ['start' => ['line' => $position->line(), 'character' => $position->character()], 'end' => ['line' => $end->line(), 'character' => $end->character()]], 'newText' => $name]];
    }

    /**
     * @param list<array<array-key, mixed>> $locations
     *
     * @return array<array-key, mixed>
     */
    private function lens(PhpClassDeclaration $class, string $title, array $locations): array
    {
        return ['range' => $this->range($class->range()), 'command' => ['title' => $title, 'command' => 'editor.action.showReferences', 'arguments' => [$class->uri(), ['line' => $class->range()->start()->line(), 'character' => $class->range()->start()->character()], $locations]]];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
