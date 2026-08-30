<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
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
        private readonly PublicAssetResolver $publicAssets,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return null;
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
            $items[] = [
                'label' => (string) $name,
                'kind' => AssetSymbolKind::Asset === $context->kind ? 17 : 12,
                'detail' => $detail,
                'textEdit' => $this->protocol->textEdit($context->range, (string) $name),
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

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
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

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (AssetSourceSymbol $candidate): array => $this->protocol->location($candidate->uri, $candidate->range), $this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name));
    }

    public function links(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $links = [];
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols as $symbol) {
            $target = $this->target($request->project, $symbol);
            if (null !== $target) {
                $links[] = ['range' => $this->protocol->range($symbol->range), 'target' => $target];
            }
        }

        return $links;
    }

    public function name(): string
    {
        return 'asset';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->importMapComplete()) {
            return [];
        }
        $known = array_fill_keys($this->entrypointNames($request->project), true);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols as $symbol) {
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
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols as $symbol) {
            if ($this->converter->containsByteOffset($request->document->text, $symbol->range, $offset, inclusiveEnd: true)) {
                return [$symbol, $request->project];
            }
        }

        return null;
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
