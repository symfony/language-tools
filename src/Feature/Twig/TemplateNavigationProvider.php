<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TemplateNavigationProvider implements DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
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

        return $this->protocol->markdownHover(\sprintf(
            "Template: `%s`\n\nFile: `%s`",
            $template->name(),
            $template->uri(),
        ));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$template] = $resolved;

        return [$this->protocol->location($template->uri(), $template->range())];
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$template, $project] = $resolved;

        return array_map(fn (TemplateReference $reference): array => $this->protocol->location($reference->uri(), $reference->range()), $this->indexes->forProject($project)->references($template->name()));
    }

    public function links(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $links = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text()) as $reference) {
            $template = $this->indexes->forProject($request->project)->get($reference->name());
            if (null !== $template) {
                $links[] = ['range' => $this->protocol->range($reference->range()), 'target' => $template->uri()];
            }
        }

        return $links;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text()) as $reference) {
            if (null === $index->get($reference->name())) {
                $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'template.not_found', \sprintf('Template "%s" does not exist in the selected environment.', $reference->name()));
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TemplateDeclaration, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $reference = $this->extractor->at($request->document->uri(), $request->document->languageId(), $request->document->text(), $offset);
        if (null === $reference) {
            return null;
        }
        $template = $this->indexes->forProject($request->project)->get($reference->name());

        return null === $template ? null : [$template, $request->project];
    }
}
