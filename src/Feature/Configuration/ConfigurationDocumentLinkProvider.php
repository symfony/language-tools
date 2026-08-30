<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationDocumentLinkProvider implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly YamlCommentParser $yamlComments,
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
        $text = $this->yamlComments->mask($request->document->text);
        preg_match_all('/\bresource\s*:\s*(["\']?)([^"\'\s#]+)\1/', $text, $matches, \PREG_OFFSET_CAPTURE);
        $basePath = Path::getDirectory($documentPath);
        foreach ($matches[2] as [$resource, $offset]) {
            if (str_contains($resource, '*') || str_starts_with($resource, '@')) {
                continue;
            }
            $targetPath = Path::isAbsolute($resource) ? Path::canonicalize($resource) : Path::join($basePath, $resource);
            $links[] = ['range' => $this->protocol->range(new Range($this->converter->toPosition($request->document->text, $offset), $this->converter->toPosition($request->document->text, $offset + \strlen($resource)))), 'target' => $this->uriToPathConverter->toUri($targetPath)];
        }

        return $links;
    }
}
