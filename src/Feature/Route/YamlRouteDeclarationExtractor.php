<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
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
    public function extract(string $uri, string $text): array
    {
        $mappings = $this->parser->parse($text);
        $routeGroups = [];
        foreach ($mappings as $mapping) {
            if ('base' !== $mapping->scope || \count($mapping->path) < 2 || !$this->hasRouteKey($mapping)) {
                continue;
            }

            $routeGroups[$mapping->path[0]] = true;
        }

        $declarations = [];
        $environmentOffsets = [];
        foreach ($mappings as $mapping) {
            if ('base' === $mapping->scope) {
                if (1 !== \count($mapping->path) || \in_array(0, $mapping->sequenceDepths, true) || !isset($routeGroups[$mapping->path[0]])) {
                    continue;
                }

                $declarations[$mapping->keyStartByte] = $this->declaration(
                    $mapping->path[0],
                    $uri,
                    $text,
                    $mapping->keyStartByte,
                    $mapping->keyEndByte,
                );
                continue;
            }

            if (!$this->hasRouteKey($mapping)) {
                continue;
            }
            $offset = strrpos(substr($text, 0, $mapping->keyStartByte), $mapping->scope);
            if (false === $offset || isset($environmentOffsets[$offset])) {
                continue;
            }
            $environmentOffsets[$offset] = true;
            $declarations[$offset] = $this->declaration(
                $mapping->scope,
                $uri,
                $text,
                $offset,
                $offset + \strlen($mapping->scope),
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
