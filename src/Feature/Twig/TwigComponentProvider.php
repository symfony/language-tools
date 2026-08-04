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
use Symfony\Lsp\Project\Project;
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
        $liveActionContext = $this->liveActionCompletionContext($project, $document->uri(), $before);
        if (null !== $liveActionContext) {
            [$component, $prefix] = $liveActionContext;
            $values = array_map(static fn (TwigComponentAction $action): string => $action->name(), $component->actions());
            $detail = \sprintf('Live action of component %s', $component->name());
        } elseif (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\s+[^>]*?([A-Za-z_][A-Za-z0-9_]*)$/', $before, $match)) {
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
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction] = $action;

            return ['contents' => ['kind' => 'markdown', 'value' => \sprintf('Live action: `%s#%s`', $component->name(), $componentAction->name())]];
        }
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component] = $resolved;
        $details = [\sprintf('%s component: `%s`', $component->isLive() ? 'Live' : 'Twig', $component->name())];
        if (null !== $component->className()) {
            $details[] = \sprintf('Class: `%s`', $component->className());
        }
        if (null !== $component->template()) {
            $details[] = \sprintf('Template: `%s`', $component->template());
        }
        if ([] !== $component->properties()) {
            $details[] = \sprintf('Properties: `%s`', implode('`, `', $component->properties()));
        }
        if ([] !== $component->actions()) {
            $details[] = \sprintf('Actions: `%s`', implode('`, `', array_map(static fn (TwigComponentAction $action): string => $action->name(), $component->actions())));
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
    }

    public function definition(array $params): ?array
    {
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = [];
            foreach ($this->indexes->forProject($project)->declarations($component->name()) as $declaration) {
                foreach ($declaration->actions() as $declarationAction) {
                    if ($componentAction->name() === $declarationAction->name()) {
                        $locations[] = ['uri' => $declaration->uri(), 'range' => $this->range($declarationAction->range())];
                    }
                }
            }

            return $locations;
        }
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
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = $this->definition($params) ?? [];
            foreach ($this->indexes->forProject($project)->actionReferences($component->name(), $componentAction->name()) as $reference) {
                $locations[] = ['uri' => $reference->uri(), 'range' => $this->range($reference->range())];
            }

            return $locations;
        }
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

    /** @return array{TwigComponent, string}|null */
    private function liveActionCompletionContext(Project $project, string $uri, string $before): ?array
    {
        $component = null;
        $value = null;
        if (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b[^>]*\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $tagComponent = $this->indexes->forProject($project)->get($match[1]);
            if ($tagComponent?->isLive()) {
                $component = $tagComponent;
                $value = $match[3];
            }
        }
        if (null === $component && preg_match('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $component = $this->componentForUri($project, $uri);
            $value = $match[2];
        }
        if (null === $component && preg_match('/\blive_action\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $component = $this->componentForUri($project, $uri);
            $value = $match[2];
        }
        if (null === $component || null === $value) {
            return null;
        }
        $parts = explode('|', $value);
        $prefix = explode(':', end($parts))[0];

        return [$component, $prefix];
    }

    private function componentForUri(Project $project, string $uri): ?TwigComponent
    {
        foreach ($this->indexes->forProject($project)->components() as $component) {
            foreach ($this->indexes->forProject($project)->declarations($component->name()) as $declaration) {
                if ($uri === $declaration->uri() && $component->isLive()) {
                    return $component;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, TwigComponentAction, Project}|null
     */
    private function resolveAction(array $params): ?array
    {
        $resolved = $this->document($params);
        if (null === $resolved) {
            return null;
        }
        [$document, $project, $position] = $resolved;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $facts = $this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text());
        foreach ($facts->actionReferences() as $reference) {
            if (!$this->contains($document->text(), $reference->range(), $offset)) {
                continue;
            }
            $component = $this->indexes->forProject($project)->get($reference->component());
            if (null === $component) {
                return null;
            }
            foreach ($component->actions() as $action) {
                if ($reference->action() === $action->name()) {
                    return [$component, $action, $project];
                }
            }
        }
        foreach ($facts->components() as $component) {
            foreach ($component->actions() as $action) {
                if ($this->contains($document->text(), $action->range(), $offset)) {
                    return [$this->indexes->forProject($project)->get($component->name()) ?? $component, $action, $project];
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, Project}|null
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
     * @return array{\Symfony\Lsp\Document\Document, Project, \Symfony\Lsp\Document\Position}|null
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
