<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class TemplateNavigationProvider implements DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateIndexRegistry $indexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$template] = $resolved;

        return $this->protocol->markdownHover(\sprintf(
            "Template: `%s`\n\nFile: `%s`",
            $template->name,
            $template->uri,
        ));
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$template] = $resolved;

        return [$this->protocol->location($template->uri, $template->range)];
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$template, $project] = $resolved;

        return array_map(fn (TemplateReference $reference): array => $this->protocol->location($reference->uri, $reference->range), $this->indexes->forProject($project)->references($template->name));
    }

    public function links(DocumentRequest $request): array
    {
        $links = [];
        foreach ($this->extractor->extract($request->source, $this->classIndexes->forProject($request->project)) as $reference) {
            $template = $this->indexes->forProject($request->project)->get($reference->name);
            if (null !== $template) {
                $links[] = $this->protocol->documentLink($reference->range, $template->uri);
            }
        }

        return $links;
    }

    public function name(): string
    {
        return 'template';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->requests->document($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        if ('twig' === $request->document->languageId && !$index->isRuntimeTemplateUri($request->document->uri)) {
            return [];
        }
        $diagnostics = [];
        foreach ($index->referencesForUri($request->document->uri) as $reference) {
            if (null === $index->get($reference->name)) {
                $diagnostics[] = $this->protocol->diagnostic($reference->range, 1, 'template.not_found', \sprintf('Template "%s" does not exist in the selected environment.', $reference->name));
            }
        }

        return $diagnostics;
    }

    /** @return array{TemplateDeclaration, Project}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $reference = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($document, $this->classIndexes->forProject($request->project)));
        if (null === $reference) {
            return null;
        }
        $template = $this->indexes->forProject($request->project)->get($reference->name);

        return null === $template ? null : [$template, $request->project];
    }
}
