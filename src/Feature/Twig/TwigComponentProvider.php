<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class TwigComponentProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $resolved = $this->document($params);
        if (null === $resolved) {
            return null;
        }
        [$document, $project, $position] = $resolved;
        if ('twig' !== $document->languageId()) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $cursor);
        $index = $this->indexes->forProject($project);
        if (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\s+[^>]*?([A-Za-z_][A-Za-z0-9_]*)$/', $before, $match)) {
            $component = $index->get($match[1]);
            if (null === $component) {
                return null;
            }
            $prefix = $match[2];
            $values = $component->properties();
            $detail = \sprintf('Property of Twig component %s', $component->name());
        } elseif (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)?$/', $before, $match)) {
            $prefix = $match[1] ?? '';
            $values = array_map(static fn (TwigComponent $component): string => $component->name(), $index->components());
            $detail = 'Symfony Twig component';
        } else {
            return null;
        }
        $start = $this->converter->toPosition($document->text(), $cursor - \strlen($prefix));
        $items = [];
        foreach ($values as $value) {
            if (!str_starts_with($value, $prefix)) {
                continue;
            }
            $items[] = [
                'label' => $value,
                'kind' => 6,
                'detail' => $detail,
                'textEdit' => [
                    'range' => [
                        'start' => ['line' => $start->line(), 'character' => $start->character()],
                        'end' => ['line' => $position->line(), 'character' => $position->character()],
                    ],
                    'newText' => $value,
                ],
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component] = $resolved;
        $details = [\sprintf('Twig component: `%s`', $component->name())];
        if (null !== $component->className()) {
            $details[] = \sprintf('Class: `%s`', $component->className());
        }
        if (null !== $component->template()) {
            $details[] = \sprintf('Template: `%s`', $component->template());
        }
        if ([] !== $component->properties()) {
            $details[] = \sprintf('Properties: `%s`', implode('`, `', $component->properties()));
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component, $project] = $resolved;

        return array_map(fn (TwigComponent $declaration): array => [
            'uri' => $declaration->uri(),
            'range' => $this->range($declaration->range()),
        ], $this->indexes->forProject($project)->declarations($component->name()));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component, $project] = $resolved;

        return array_map(fn (TwigComponentReference $reference): array => [
            'uri' => $reference->uri(),
            'range' => $this->range($reference->range()),
        ], $this->indexes->forProject($project)->references($component->name()));
    }

    public function diagnostics(array $params): ?array
    {
        $resolved = $this->document($params);
        if (null === $resolved) {
            return null;
        }
        [$document, $project] = $resolved;
        if ('twig' !== $document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        if (!$index->isComplete()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($project, $document->uri(), 'twig', $document->text())->references() as $reference) {
            if (null === $index->get($reference->name())) {
                $diagnostics[] = [
                    'range' => $this->range($reference->range()),
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => 'twig_component.not_found',
                    'message' => \sprintf('Twig component "%s" does not exist.', $reference->name()),
                ];
            }
        }

        return $diagnostics;
    }

    public function codeLenses(array $params): ?array
    {
        $resolved = $this->document($params);
        if (null === $resolved) {
            return null;
        }
        [$document, $project] = $resolved;
        if ('php' !== $document->languageId()) {
            return null;
        }
        $lenses = [];
        foreach ($this->extractor->extract($project, $document->uri(), 'php', $document->text())->components() as $component) {
            $references = $this->indexes->forProject($project)->references($component->name());
            $locations = array_map(fn (TwigComponentReference $reference): array => [
                'uri' => $reference->uri(),
                'range' => $this->range($reference->range()),
            ], $references);
            $count = \count($locations);
            $lenses[] = [
                'range' => $this->range($component->range()),
                'command' => [
                    'title' => \sprintf('%d Twig component usage%s', $count, 1 === $count ? '' : 's'),
                    'command' => 'editor.action.showReferences',
                    'arguments' => [
                        $component->uri(),
                        ['line' => $component->range()->start()->line(), 'character' => $component->range()->start()->character()],
                        $locations,
                    ],
                ],
            ];
        }

        return $lenses;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, \Symfony\Lsp\Project\Project}|null
     */
    private function resolve(array $params): ?array
    {
        $resolved = $this->document($params);
        if (null === $resolved) {
            return null;
        }
        [$document, $project, $position] = $resolved;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $facts = $this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text());
        foreach ($facts->references() as $reference) {
            if ($this->contains($document->text(), $reference->range(), $offset)) {
                $component = $this->indexes->forProject($project)->get($reference->name());

                return null === $component ? null : [$component, $project];
            }
        }
        foreach ($facts->components() as $component) {
            if ($this->contains($document->text(), $component->range(), $offset)) {
                return [$this->indexes->forProject($project)->get($component->name()) ?? $component, $project];
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{\Symfony\Lsp\Document\Document, \Symfony\Lsp\Project\Project, \Symfony\Lsp\Document\Position}|null
     */
    private function document(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return null;
        }
        $position = $params['position'] ?? [];
        $line = \is_array($position) && \is_int($position['line'] ?? null) ? $position['line'] : 0;
        $character = \is_array($position) && \is_int($position['character'] ?? null) ? $position['character'] : 0;

        return [$document, $project, new \Symfony\Lsp\Document\Position($line, $character)];
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return [
            'start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()],
            'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()],
        ];
    }
}
