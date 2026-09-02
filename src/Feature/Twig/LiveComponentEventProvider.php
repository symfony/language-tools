<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class LiveComponentEventProvider implements CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
        private readonly PhpCommentParserInterface $phpComments,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request || 'php' !== $request->document->languageId || !str_contains($request->document->text, 'AsLiveComponent')) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $before = substr($this->phpComments->mask($request->document->text), 0, $offset);
        if (!preg_match('/(?:->|\b)emit\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return null;
        }
        $prefix = $match[2];
        $start = $this->converter->toPosition($request->document->text, $offset - \strlen($prefix));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->eventNames() as $event) {
            if (str_starts_with($event, $prefix)) {
                $items[] = [
                    'label' => $event,
                    'kind' => 23,
                    'detail' => 'Live component event',
                    'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $event),
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
        $details = [\sprintf('Live component event: `%s`', $event->name)];
        foreach ($this->indexes->forProject($project)->events($event->name) as $candidate) {
            if ($candidate->declaration && null !== $candidate->component && null !== $candidate->action) {
                $details[] = \sprintf('Listener: `%s#%s`', $candidate->component, $candidate->action);
            }
        }

        return $this->protocol->markdownHover(implode("\n\n", array_values(array_unique($details))));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$event, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->events($event->name) as $candidate) {
            if ($candidate->declaration) {
                $locations[] = $this->protocol->location($candidate->uri, $candidate->range);
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

        return array_map(fn (LiveComponentEvent $candidate): array => $this->protocol->location($candidate->uri, $candidate->range), $this->indexes->forProject($project)->events($event->name));
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{LiveComponentEvent, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $document = SourceDocument::fromDocument($request->document);
        $event = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($request->project, $document)->events);

        return null === $event ? null : [$event, $request->project];
    }
}
