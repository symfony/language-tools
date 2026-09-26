<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class AssetProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly AssetIndexRegistry $indexes,
        private readonly AssetSourceIndexRegistry $sourceIndexes,
        private readonly AssetExtractor $extractor,
        private readonly PublicAssetResolver $publicAssets,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $offset = $request->offset;
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return [];
        }
        if (AssetSymbolKind::Asset === $context->kind) {
            $candidates = [];
            foreach ($this->indexes->forProject($request->project)->assets() as $asset) {
                $candidates[$asset->logicalPath] = 'AssetMapper asset';
            }
            foreach ($this->publicAssets->logicalPaths($request->project) as $path) {
                $candidates[$path] ??= 'Public asset';
            }
            ksort($candidates);
        } else {
            $candidates = array_fill_keys($this->entrypointNames($request->project), 'Importmap entrypoint');
        }
        $items = [];
        foreach ($candidates as $name => $detail) {
            if (!str_starts_with((string) $name, $context->prefix)) {
                continue;
            }
            $items[] = $this->protocol->completionItem(
                (string) $name,
                AssetSymbolKind::Asset === $context->kind ? CompletionItemKind::File : CompletionItemKind::Value,
                $detail,
                $this->protocol->textEdit($context->range, (string) $name),
            );
        }

        return $items;
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        if (AssetSymbolKind::Asset === $symbol->kind) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name);
            if (null === $asset) {
                $path = $this->publicAssets->path($project, $symbol->name);

                return null === $path ? null : $this->protocol->markdownHover(\sprintf(
                    "Public asset: `%s`\n\nSource: `%s`",
                    $symbol->name,
                    $path,
                ));
            }

            return $this->protocol->markdownHover(\sprintf(
                "AssetMapper asset: `%s`\n\nSource: `%s`\n\nVendor: %s",
                $asset->logicalPath,
                $asset->sourcePath,
                $asset->vendor ? 'yes' : 'no',
            ));
        }
        $entry = $this->indexes->forProject($project)->importMapEntry($symbol->name);
        $lines = ['Importmap entrypoint: `'.$symbol->name.'`'];
        if (null !== $entry) {
            $lines[] = '';
            $lines[] = 'Path: `'.$entry->path.'`';
            if (null !== $entry->version) {
                $lines[] = '';
                $lines[] = 'Version: `'.$entry->version.'`';
            }
        }

        return $this->protocol->markdownHover(implode("\n", $lines));
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $project] = $resolved;
        if (AssetSymbolKind::Asset === $symbol->kind) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name);
            $path = null !== $asset ? $asset->sourcePath : $this->publicAssets->path($project, $symbol->name);

            return null === $path ? [] : [['uri' => $this->uriConverter->toUri($path), 'range' => $this->protocol->zeroRange()]];
        }
        $declarations = array_values(array_filter(
            $this->sourceIndexes->forProject($project)->symbols(AssetSymbolKind::Entrypoint, $symbol->name),
            static fn (AssetSourceSymbol $candidate): bool => $candidate->declaration,
        ));

        return array_map(fn (AssetSourceSymbol $candidate): array => $this->protocol->location($candidate->uri, $candidate->range), $declarations);
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $project] = $resolved;

        return $this->protocol->locations($request->reported($this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name)));
    }

    public function links(DocumentRequest $request): array
    {
        if ('twig' !== $request->document->languageId) {
            return [];
        }
        $links = [];
        foreach ($this->extractor->extract($request->source)->symbols as $symbol) {
            $target = $this->target($request->project, $symbol);
            if (null !== $target) {
                $links[] = $this->protocol->documentLink($symbol->range, $target);
            }
        }

        return $links;
    }

    public function name(): string
    {
        return 'asset';
    }

    public function diagnostics(DocumentRequest $request): ?array
    {
        if ('twig' !== $request->document->languageId) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->importMapComplete()) {
            return [];
        }
        $known = array_fill_keys($this->entrypointNames($request->project), true);
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $diagnostics = [];
        foreach ($facts instanceof AssetSourceFacts ? $facts->symbols : [] as $symbol) {
            if (AssetSymbolKind::Entrypoint !== $symbol->kind || isset($known[$symbol->name])) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic(
                $symbol->range,
                1,
                'importmap.unknown_entrypoint',
                \sprintf('Unknown importmap entrypoint "%s".', $symbol->name),
            );
        }

        return $diagnostics;
    }

    /** @return list<string> */
    private function entrypointNames(Project $project): array
    {
        $names = $this->sourceIndexes->forProject($project)->declarationNames(AssetSymbolKind::Entrypoint);
        foreach ($this->indexes->forProject($project)->importMapEntries() as $entry) {
            if ($entry->entrypoint) {
                $names[] = $entry->name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return array{AssetSourceSymbol, Project}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $symbol = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($document)->symbols);

        return null === $symbol ? null : [$symbol, $request->project];
    }

    private function target(Project $project, AssetSourceSymbol $symbol): ?string
    {
        if (AssetSymbolKind::Asset === $symbol->kind) {
            $asset = $this->indexes->forProject($project)->asset($symbol->name);
            $path = null !== $asset ? $asset->sourcePath : $this->publicAssets->path($project, $symbol->name);

            return null === $path ? null : $this->uriConverter->toUri($path);
        }
        foreach ($this->sourceIndexes->forProject($project)->symbols(AssetSymbolKind::Entrypoint, $symbol->name) as $candidate) {
            if ($candidate->declaration) {
                return $candidate->uri;
            }
        }

        return null;
    }
}
