<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;

final class LiveComponentEventProvider implements CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        if ('php' !== $document->languageId() || !str_contains($document->text(), 'AsLiveComponent')) {
            return null;
        }
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $offset);
        if (!preg_match('/(?:->|\b)emit\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return null;
        }
        $prefix = $match[2];
        $start = $this->converter->toPosition($document->text(), $offset - \strlen($prefix));
        $items = [];
        foreach ($this->indexes->forProject($project)->eventNames() as $event) {
            if (str_starts_with($event, $prefix)) {
                $items[] = [
                    'label' => $event,
                    'kind' => 23,
                    'detail' => 'Live component event',
                    'textEdit' => [
                        'range' => [
                            'start' => ['line' => $start->line(), 'character' => $start->character()],
                            'end' => ['line' => $position->line(), 'character' => $position->character()],
                        ],
                        'newText' => $event,
                    ],
                ];
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
        [$event, $project] = $resolved;
        $details = [\sprintf('Live component event: `%s`', $event->name())];
        foreach ($this->indexes->forProject($project)->events($event->name()) as $candidate) {
            if ($candidate->isDeclaration() && null !== $candidate->component() && null !== $candidate->action()) {
                $details[] = \sprintf('Listener: `%s#%s`', $candidate->component(), $candidate->action());
            }
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", array_values(array_unique($details)))]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$event, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->events($event->name()) as $candidate) {
            if ($candidate->isDeclaration()) {
                $locations[] = ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())];
            }
        }

        return $locations;
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$event, $project] = $resolved;

        return array_map(fn (LiveComponentEvent $candidate): array => [
            'uri' => $candidate->uri(),
            'range' => $this->range($candidate->range()),
        ], $this->indexes->forProject($project)->events($event->name()));
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{LiveComponentEvent, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        foreach ($this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text())->events() as $event) {
            if ($this->contains($document, $event->range(), $offset)) {
                return [$event, $project];
            }
        }

        return null;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
