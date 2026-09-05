<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlScalarStyle;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationDocumentLinkProvider implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly YamlDocumentParser $yaml,
    ) {
    }

    public function links(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'yaml' !== $request->document->languageId) {
            return null;
        }
        $documentPath = $this->uriToPathConverter->convert($request->document->uri);
        if (null === $documentPath) {
            return [];
        }
        $links = [];
        $basePath = Path::getDirectory($documentPath);
        foreach ($this->yaml->parseDocument($request->document->text)->scalars as $scalar) {
            $resource = $scalar->value;
            if (!$this->isResourceValue($scalar) || '' === $resource || str_contains($resource, '*') || str_starts_with($resource, '@')) {
                continue;
            }
            $targetPath = Path::isAbsolute($resource) ? Path::canonicalize($resource) : Path::join($basePath, $resource);
            $links[] = [
                'range' => $this->protocol->range(new Range(
                    $this->converter->toPosition($request->document->text, $scalar->contentStartByte),
                    $this->converter->toPosition($request->document->text, $scalar->contentEndByte),
                )),
                'target' => $this->uriToPathConverter->toUri($targetPath),
            ];
        }

        return $links;
    }

    private function isResourceValue(YamlScalar $scalar): bool
    {
        return 'resource' === ($scalar->path[\count($scalar->path) - 1] ?? null)
            && !\in_array($scalar->style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true);
    }
}
