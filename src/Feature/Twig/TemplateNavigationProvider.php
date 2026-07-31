<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class TemplateNavigationProvider implements DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateIndexRegistry $indexes,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$template] = $resolved;

        return ['contents' => ['kind' => 'markdown', 'value' => \sprintf(
            "Template: `%s`\n\nFile: `%s`",
            $template->name(),
            $template->uri(),
        )]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$template] = $resolved;

        return [$this->location($template)];
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$template, $project] = $resolved;

        return array_map(fn (TemplateReference $reference): array => [
            'uri' => $reference->uri(),
            'range' => $this->range($reference),
        ], $this->indexes->forProject($project)->references($template->name()));
    }

    public function links(array $params): ?array
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
        $links = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text()) as $reference) {
            $template = $this->indexes->forProject($project)->get($reference->name());
            if (null !== $template) {
                $links[] = ['range' => $this->range($reference), 'target' => $template->uri()];
            }
        }

        return $links;
    }

    public function diagnostics(array $params): ?array
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
        $index = $this->indexes->forProject($project);
        if (!$index->isComplete()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text()) as $reference) {
            if (null === $index->get($reference->name())) {
                $diagnostics[] = [
                    'range' => $this->range($reference),
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => 'template.not_found',
                    'message' => \sprintf('Template "%s" does not exist in the selected environment.', $reference->name()),
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TemplateDeclaration, \Symfony\Lsp\Project\Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $reference = $this->extractor->at($document->uri(), $document->languageId(), $document->text(), $offset);
        if (null === $reference) {
            return null;
        }
        $template = $this->indexes->forProject($project)->get($reference->name());

        return null === $template ? null : [$template, $project];
    }

    /** @return array{uri: string, range: array<string, array{line: int, character: int}>} */
    private function location(TemplateDeclaration $template): array
    {
        return ['uri' => $template->uri(), 'range' => [
            'start' => ['line' => $template->range()->start()->line(), 'character' => $template->range()->start()->character()],
            'end' => ['line' => $template->range()->end()->line(), 'character' => $template->range()->end()->character()],
        ]];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(TemplateReference $reference): array
    {
        return [
            'start' => ['line' => $reference->range()->start()->line(), 'character' => $reference->range()->start()->character()],
            'end' => ['line' => $reference->range()->end()->line(), 'character' => $reference->range()->end()->character()],
        ];
    }
}
