<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class AssetProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly AssetIndexRegistry $indexes,
        private readonly AssetSourceIndexRegistry $sourceIndexes,
        private readonly AssetExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $context = $this->extractor->completionContext($request->document->languageId(), $request->document->text(), $offset);
        if (null === $context) {
            return null;
        }
        $names = AssetSymbolKind::Asset === $context->kind()
            ? array_map(static fn (Asset $asset): string => $asset->logicalPath(), $this->indexes->forProject($request->project)->assets())
            : $this->entrypointNames($request->project);
        $items = [];
        foreach ($names as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => AssetSymbolKind::Asset === $context->kind() ? 17 : 12,
                'detail' => AssetSymbolKind::Asset === $context->kind() ? 'AssetMapper asset' : 'Importmap entrypoint',
                'textEdit' => ['range' => $this->protocol->range($context->range()), 'newText' => $name],
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
        [$symbol, $project] = $resolved;
        if (AssetSymbolKind::Asset === $symbol->kind()) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name());
            if (null === $asset) {
                return null;
            }

            return ['contents' => ['kind' => 'markdown', 'value' => \sprintf(
                "AssetMapper asset: `%s`\n\nSource: `%s`\n\nVendor: %s",
                $asset->logicalPath(),
                $asset->sourcePath(),
                $asset->isVendor() ? 'yes' : 'no',
            )]];
        }
        $entry = $this->indexes->forProject($project)->importMapEntry($symbol->name());
        $lines = ['Importmap entrypoint: `'.$symbol->name().'`'];
        if (null !== $entry) {
            $lines[] = '';
            $lines[] = 'Path: `'.$entry->path().'`';
            if (null !== $entry->version()) {
                $lines[] = '';
                $lines[] = 'Version: `'.$entry->version().'`';
            }
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n", $lines)]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        if (AssetSymbolKind::Asset === $symbol->kind()) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name());

            return null === $asset ? [] : [['uri' => $this->uriConverter->toUri($asset->sourcePath()), 'range' => $this->protocol->zeroRange()]];
        }
        $declarations = array_values(array_filter(
            $this->sourceIndexes->forProject($project)->symbols(AssetSymbolKind::Entrypoint, $symbol->name()),
            static fn (AssetSourceSymbol $candidate): bool => $candidate->isDeclaration(),
        ));

        return array_map(fn (AssetSourceSymbol $candidate): array => $this->protocol->location($candidate->uri(), $candidate->range()), $declarations);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (AssetSourceSymbol $candidate): array => $this->protocol->location($candidate->uri(), $candidate->range()), $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()));
    }

    public function links(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $links = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            $target = $this->target($request->project, $symbol);
            if (null !== $target) {
                $links[] = ['range' => $this->protocol->range($symbol->range()), 'target' => $target];
            }
        }

        return $links;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->importMapComplete()) {
            return [];
        }
        $known = array_fill_keys($this->entrypointNames($request->project), true);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if (AssetSymbolKind::Entrypoint !== $symbol->kind() || isset($known[$symbol->name()])) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic(
                $symbol->range(),
                1,
                'importmap.unknown_entrypoint',
                \sprintf('Unknown importmap entrypoint "%s".', $symbol->name()),
            );
        }

        return $diagnostics;
    }

    /** @return list<string> */
    private function entrypointNames(Project $project): array
    {
        $names = $this->sourceIndexes->forProject($project)->declarationNames(AssetSymbolKind::Entrypoint);
        foreach ($this->indexes->forProject($project)->importMapEntries() as $entry) {
            if ($entry->isEntrypoint()) {
                $names[] = $entry->name();
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{AssetSourceSymbol, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($this->contains($request->document, $symbol->range(), $offset)) {
                return [$symbol, $request->project];
            }
        }

        return null;
    }

    private function target(Project $project, AssetSourceSymbol $symbol): ?string
    {
        if (AssetSymbolKind::Asset === $symbol->kind()) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name());

            return null === $asset ? null : $this->uriConverter->toUri($asset->sourcePath());
        }
        foreach ($this->sourceIndexes->forProject($project)->symbols(AssetSymbolKind::Entrypoint, $symbol->name()) as $candidate) {
            if ($candidate->isDeclaration()) {
                return $candidate->uri();
            }
        }

        return null;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }
}
