<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\NamespaceUseGroupClause;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration;

final class PhpNameContextBuilder
{
    public function __construct(
        private readonly TolerantPhpNodeAdapter $nodes,
        private readonly TolerantPhpScopeResolver $scopes,
    ) {
    }

    public function build(TolerantPhpNodeCollection $collection, string $source): PhpNameContext
    {
        $namespaceDefinition = null;
        $namespaceFound = false;
        $namespace = '';
        $imports = [];
        foreach ($collection->nameContextNodes as $node) {
            if (!$namespaceFound && $node instanceof NamespaceDefinition) {
                $namespaceDefinition = $node;
                $namespaceFound = true;
                $namespace = $node->name instanceof QualifiedName ? trim($this->scopes->qualifiedName($node->name, $source), '\\') : '';
            } elseif ($node instanceof NamespaceUseDeclaration && null === $node->functionOrConst && $node->getNamespaceDefinition() === $namespaceDefinition) {
                $this->addImports($node, $source, $imports);
            }
        }

        return new PhpNameContext($namespace, $imports);
    }

    /** @param array<string, string> $imports */
    private function addImports(NamespaceUseDeclaration $declaration, string $source, array &$imports): void
    {
        if (null === $declaration->useClauses) {
            return;
        }
        foreach ($declaration->useClauses->children as $clause) {
            if (!$clause instanceof NamespaceUseClause) {
                continue;
            }
            $prefix = $clause->namespaceName instanceof QualifiedName ? trim($this->scopes->qualifiedName($clause->namespaceName, $source), '\\') : '';
            if (null !== $clause->groupClauses) {
                foreach ($clause->groupClauses->children as $group) {
                    if (!$group instanceof NamespaceUseGroupClause || null !== $group->functionOrConst) {
                        continue;
                    }
                    $name = trim($this->scopes->qualifiedName($group->namespaceName, $source), '\\');
                    $class = '' === $prefix ? $name : $prefix.'\\'.$name;
                    $imports[$this->alias($group, $name, $source)] = $class;
                }

                continue;
            }
            $imports[$this->alias($clause, $prefix, $source)] = $prefix;
        }
    }

    private function alias(NamespaceUseClause|NamespaceUseGroupClause $clause, string $name, string $source): string
    {
        $alias = $this->nodes->namespaceAliasingClause($clause);
        if (null !== $alias) {
            return (string) $alias->name->getText($source);
        }

        return substr($name, (int) strrpos('\\'.$name, '\\'));
    }
}
