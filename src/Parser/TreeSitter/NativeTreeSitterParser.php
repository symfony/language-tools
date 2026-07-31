<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class NativeTreeSitterParser implements TreeSitterParserInterface
{
    public function parse(string $language, string $source): TreeSitterTree
    {
        if (!\function_exists('symfony_lsp_tree_sitter_parse')) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter extension is not loaded.');
        }

        $result = symfony_lsp_tree_sitter_parse($language, $source);
        if (!\is_array($result)) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter extension returned an invalid tree.');
        }
        $rawNodes = $result['nodes'] ?? null;
        if (!\is_bool($result['hasError'] ?? null) || !\is_array($rawNodes) || [] === $rawNodes || !array_is_list($rawNodes)) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter extension returned an invalid tree.');
        }

        $children = array_fill(0, \count($rawNodes), []);
        /** @var list<array{type: string, startByte: int, endByte: int, parent: int, field: string|null, error: bool, missing: bool, hasError: bool}> $validatedNodes */
        $validatedNodes = [];
        $sourceLength = \strlen($source);
        foreach ($rawNodes as $node) {
            $this->validateNode($node, $sourceLength);
            $index = \count($validatedNodes);
            $validatedNodes[] = $node;
            $parent = $node['parent'];
            if (-1 === $parent) {
                if (0 !== $index) {
                    throw new \RuntimeException('The Symfony LSP Tree-sitter extension returned more than one root node.');
                }

                continue;
            }
            if ($parent < 0 || $parent >= $index) {
                throw new \RuntimeException('The Symfony LSP Tree-sitter extension returned an invalid parent node.');
            }
            $children[$parent][] = $index;
        }

        $nodes = [];
        foreach ($validatedNodes as $index => $node) {
            $nodes[] = new TreeSitterNode(
                $node['type'],
                $node['startByte'],
                $node['endByte'],
                -1 === $node['parent'] ? null : $node['parent'],
                $node['field'],
                $node['error'],
                $node['missing'],
                $node['hasError'],
                $children[$index],
            );
        }

        return new TreeSitterTree($result['hasError'], $nodes);
    }

    /** @phpstan-assert array{type: string, startByte: int, endByte: int, parent: int, field: string|null, error: bool, missing: bool, hasError: bool} $node */
    private function validateNode(mixed $node, int $sourceLength): void
    {
        if (!\is_array($node)
            || !\is_string($node['type'] ?? null)
            || !\is_int($node['startByte'] ?? null)
            || !\is_int($node['endByte'] ?? null)
            || $node['startByte'] < 0
            || $node['endByte'] < $node['startByte']
            || $node['endByte'] > $sourceLength
            || !\is_int($node['parent'] ?? null)
            || (null !== ($node['field'] ?? null) && !\is_string($node['field']))
            || !\is_bool($node['error'] ?? null)
            || !\is_bool($node['missing'] ?? null)
            || !\is_bool($node['hasError'] ?? null)
        ) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter extension returned an invalid node.');
        }
    }
}
