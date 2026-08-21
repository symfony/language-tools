<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\DocumentContextResolver;
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
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly EventSourceIndexRegistry $sourceIndexes,
        private readonly EventExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $prefix = $this->extractor->completionPrefix($request->document->languageId(), $request->document->text(), $offset);
        if (null === $prefix) {
            return null;
        }
        $items = [];
        foreach ($this->indexes->forProject($request->project)->events() as $event) {
            if (str_starts_with($event->name(), $prefix)) {
                $items[] = $this->completion($event->name(), $request->document->text(), $offset - \strlen($prefix), $request->position);
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

        return $this->protocol->markdownHover('Event listener: `'.$class->className().'`'."\n\n".'Events: `'.implode('`, `', $events).'`');
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
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->invalidListenerMethods() as $listener) {
            $diagnostics[] = $this->protocol->diagnostic(
                $listener->range(),
                1,
                'event.invalid_listener_method',
                \sprintf('Event listener method "%s::%s" does not exist.', $listener->className(), $listener->method()),
            );
        }

        return $diagnostics;
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $lenses = [];
        foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
            $listeners = $index->listenersForEvent($class->className());
            if (null !== $index->event($class->className()) || [] !== $listeners) {
                $related = array_values(array_unique(array_map(static fn (EventListener $listener): string => $listener->className(), $listeners)));
                $count = \count($related);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('%d event listener%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->classLocations($request->project, $related));
                continue;
            }
            $handled = $index->listenersByClass($class->className());
            if ([] !== $handled) {
                $events = array_values(array_unique(array_map(static fn (EventListener $listener): string => $listener->event(), $handled)));
                $locations = [];
                foreach ($events as $event) {
                    if (null !== $eventClass = $index->event($event)?->className()) {
                        array_push($locations, ...$this->classLocations($request->project, [$eventClass]));
                    }
                }
                $count = \count($events);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('Listens to %d event%s', $count, 1 === $count ? '' : 's'), $class->uri(), $locations);
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
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($this->contains($request->document->text(), $symbol->range(), $offset)) {
                return [$symbol, null, $request->project];
            }
        }
        if ('php' === $request->document->languageId()) {
            foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
                if ($this->contains($request->document->text(), $class->range(), $offset)) {
                    return [null, $class, $request->project];
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

        return $this->protocol->markdownHover(implode("\n", $lines));
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
        return array_map(fn (EventSourceSymbol $symbol): array => $this->protocol->location($symbol->uri(), $symbol->range()), $this->sourceIndexes->forProject($project)->symbols($name));
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
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
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

        return ['label' => $name, 'kind' => 12, 'textEdit' => $this->protocol->textEdit(new Range($position, $end), $name)];
    }
}
