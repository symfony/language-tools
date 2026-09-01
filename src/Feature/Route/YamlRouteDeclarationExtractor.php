<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;

final class YamlRouteDeclarationExtractor
{
    private const ROUTE_KEYS = [
        'path' => true,
        'controller' => true,
        'methods' => true,
        'host' => true,
        'schemes' => true,
        'condition' => true,
        'defaults' => true,
        'requirements' => true,
        'options' => true,
        'alias' => true,
    ];

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly YamlDocumentParser $parser,
    ) {
    }

    /**
     * @return list<RouteDeclaration>
     */
    public function extract(SourceDocument $document): array
    {
        $mappings = $this->parser->parse($document->text);
        $routeGroups = [];
        foreach ($mappings as $mapping) {
            if (\count($mapping->path) < 2 || !$this->hasRouteKey($mapping)) {
                continue;
            }

            $routeGroups[$mapping->scope][$mapping->path[0]] = true;
        }

        $declarations = [];
        foreach ($mappings as $mapping) {
            if (1 !== \count($mapping->path)
                || \in_array(0, $mapping->sequenceDepths, true)
                || !isset($routeGroups[$mapping->scope][$mapping->path[0]])
            ) {
                continue;
            }

            $declarations[$mapping->keyStartByte] = $this->declaration(
                $mapping->path[0],
                $document->uri,
                $document->text,
                $mapping->keyStartByte,
                $mapping->keyEndByte,
            );
        }
        ksort($declarations);

        return array_values($declarations);
    }

    private function hasRouteKey(YamlMapping $mapping): bool
    {
        if ([] === $mapping->path) {
            return false;
        }

        return isset(self::ROUTE_KEYS[$mapping->path[\count($mapping->path) - 1]]);
    }

    private function declaration(string $name, string $uri, string $text, int $startByte, int $endByte): RouteDeclaration
    {
        return new RouteDeclaration(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $startByte),
                $this->positionConverter->toPosition($text, $endByte),
            ),
        );
    }
}
