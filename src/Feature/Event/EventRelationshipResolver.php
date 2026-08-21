<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventRelationshipResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly EventSourceIndexRegistry $sourceIndexes,
        private readonly EventExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{EventSourceSymbol|null, PhpClassDeclaration|null, Project}|null
     */
    public function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
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
    public function eventHover(EventIndex $index, string $name): ?array
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
        $listenerNames = [];
        foreach ($listeners as $listener) {
            $listenerNames[] = $listener->className().'::'.$listener->method().' ('.$listener->priority().')';
        }
        $lines[] = '';
        $lines[] = 'Listeners: '.([] === $listenerNames ? 'none' : '`'.implode('`, `', $listenerNames).'`');

        return $this->protocol->markdownHover(implode("\n", $lines));
    }

    /** @return list<array<array-key, mixed>> */
    public function eventDefinitionLocations(Project $project, EventIndex $index, string $name): array
    {
        $classes = [];
        if (null !== $eventClass = $index->event($name)?->className()) {
            $classes[$eventClass] = true;
        }
        foreach ($index->listenersForEvent($name) as $listener) {
            $classes[$listener->className()] = true;
        }

        return $this->classLocations($project, array_keys($classes));
    }

    /** @return list<array<array-key, mixed>> */
    public function sourceLocations(Project $project, string $name): array
    {
        $locations = [];
        foreach ($this->sourceIndexes->forProject($project)->symbols($name) as $symbol) {
            $locations[] = $this->protocol->location($symbol->uri(), $symbol->range());
        }

        return $locations;
    }

    /**
     * @param list<string> $classNames
     *
     * @return list<array<array-key, mixed>>
     */
    public function classLocations(Project $project, array $classNames): array
    {
        $locations = [];
        $uniqueClasses = [];
        foreach ($classNames as $className) {
            $uniqueClasses[$className] = true;
        }
        foreach (array_keys($uniqueClasses) as $className) {
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
    public function uniqueLocations(array $locations): array
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
}
