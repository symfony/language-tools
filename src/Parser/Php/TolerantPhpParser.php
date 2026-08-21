<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\ClassBaseClause;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\NamespaceAliasingClause;
use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\NamespaceUseGroupClause;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;

final class TolerantPhpParser implements PhpParserInterface
{
    public function __construct(
        private readonly Parser $parser,
    ) {
    }

    public function parse(string $source): PhpDocument
    {
        $root = $this->parser->parseSourceFile($source);
        $attributes = [];
        $methodCalls = [];
        $typeDeclarations = [];
        $namespaceDefinition = null;
        $namespaceFound = false;
        $namespace = '';
        $imports = [];

        foreach ($root->getDescendantNodes() as $node) {
            if ($node instanceof Attribute) {
                $attributes[] = $this->attribute($node, $source);
            } elseif ($node instanceof CallExpression && $node->callableExpression instanceof MemberAccessExpression) {
                $call = $this->methodCall($node, $source);
                if (null !== $call) {
                    $methodCalls[] = $call;
                }
            } elseif ($node instanceof ClassDeclaration || $node instanceof InterfaceDeclaration || $node instanceof TraitDeclaration || $node instanceof EnumDeclaration) {
                $typeDeclarations[] = $this->typeDeclaration($node, $source);
            }
            if (!$namespaceFound && $node instanceof NamespaceDefinition) {
                $namespaceDefinition = $node;
                $namespaceFound = true;
                $namespace = $node->name instanceof QualifiedName ? trim($this->qualifiedName($node->name, $source), '\\') : '';
            } elseif ($node instanceof NamespaceUseDeclaration && null === $node->functionOrConst && $node->getNamespaceDefinition() === $namespaceDefinition) {
                $this->addImports($node, $source, $imports);
            }
        }

        $diagnostics = [];
        foreach (DiagnosticsProvider::getDiagnostics($root) as $diagnostic) {
            $diagnostics[] = new PhpDiagnostic(
                $diagnostic->message,
                $diagnostic->start,
                $diagnostic->start + $diagnostic->length,
            );
        }

        $names = new PhpNameContext($namespace, $imports);

        return new PhpDocument($attributes, $methodCalls, $typeDeclarations, $diagnostics, $this->typedVariables($source, $names), $names);
    }

    /** @return list<PhpTypedVariable> */
    private function typedVariables(string $source, PhpNameContext $names): array
    {
        $typePattern = '\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:\\s*[|&]\\s*\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)*';
        preg_match_all('/('.$typePattern.')\\s+\\$([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[2][1]] = new PhpTypedVariable($match[2][0], $this->resolveTypes($match[1][0], $names));
        }
        preg_match_all('/('.$typePattern.')\\s+\\$[A-Za-z_][A-Za-z0-9_]*(?:\\s*=[^,;]*)?((?:\\s*,\\s*\\$[A-Za-z_][A-Za-z0-9_]*(?:\\s*=[^,;]*)?)*)\\s*;/', $source, $declarations, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($declarations as $declaration) {
            preg_match_all('/\\$([A-Za-z_][A-Za-z0-9_]*)/', $declaration[2][0], $additional, \PREG_OFFSET_CAPTURE);
            foreach ($additional[1] as [$name, $offset]) {
                $variables[$declaration[2][1] + $offset] = new PhpTypedVariable($name, $this->resolveTypes($declaration[1][0], $names));
            }
        }
        ksort($variables);

        return array_values($variables);
    }

    /** @return list<string> */
    private function resolveTypes(string $types, PhpNameContext $names): array
    {
        $types = preg_split('/\\s*[|&]\\s*/', $types);
        if (false === $types) {
            return [];
        }
        $resolved = [];
        foreach ($types as $type) {
            $resolved[] = $names->resolve(ltrim($type, '?'));
        }

        return array_values(array_unique($resolved));
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
            $prefix = $clause->namespaceName instanceof QualifiedName ? trim($this->qualifiedName($clause->namespaceName, $source), '\\') : '';
            if (null !== $clause->groupClauses) {
                foreach ($clause->groupClauses->children as $group) {
                    if (!$group instanceof NamespaceUseGroupClause || null !== $group->functionOrConst) {
                        continue;
                    }
                    $name = trim($this->qualifiedName($group->namespaceName, $source), '\\');
                    $class = '' === $prefix ? $name : $prefix.'\\'.$name;
                    $imports[$this->alias($group, $name, $source)] = $class;
                }

                continue;
            }
            $imports[$this->alias($clause, $prefix, $source)] = $prefix;
        }
    }

    private function qualifiedName(QualifiedName $name, string $source): string
    {
        $text = $name->globalSpecifier instanceof Token ? (string) $name->globalSpecifier->getText($source) : '';
        if (null !== $name->relativeSpecifier) {
            $text .= $name->relativeSpecifier->getText($source);
        }
        foreach ($name->nameParts as $part) {
            if ($part instanceof Token) {
                $text .= $part->getText($source);
            }
        }

        return $text;
    }

    private function alias(NamespaceUseClause|NamespaceUseGroupClause $clause, string $name, string $source): string
    {
        $alias = get_object_vars($clause)['namespaceAliasingClause'] ?? null;
        if ($alias instanceof NamespaceAliasingClause) {
            return (string) $alias->name->getText($source);
        }

        return substr($name, (int) strrpos('\\'.$name, '\\'));
    }

    private function typeDeclaration(ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $declaration, string $source): PhpTypeDeclaration
    {
        $parentClassName = null;
        if ($declaration instanceof ClassDeclaration) {
            $classBaseClause = get_object_vars($declaration)['classBaseClause'] ?? null;
            if ($classBaseClause instanceof ClassBaseClause) {
                $parentClassName = $classBaseClause->baseClass->getResolvedName();
            }
        }

        return new PhpTypeDeclaration(
            (string) $declaration->getNamespacedName(),
            null === $parentClassName ? null : (string) $parentClassName,
            $declaration->name->getStartPosition(),
            $declaration->name->getEndPosition(),
            $declaration->getStartPosition(),
            $declaration->getEndPosition(),
            $declaration instanceof ClassDeclaration,
        );
    }

    private function attribute(Attribute $attribute, string $source): PhpAttribute
    {
        return new PhpAttribute(
            $this->attributeName($attribute->name, $source),
            $this->arguments($attribute->argumentExpressionList->children ?? [], $source),
        );
    }

    private function methodCall(CallExpression $call, string $source): ?PhpMethodCall
    {
        $memberAccess = $call->callableExpression;
        if (!$memberAccess instanceof MemberAccessExpression) {
            return null;
        }
        $receiver = $memberAccess->dereferencableExpression->getText($source);
        $method = $memberAccess->memberName->getText($source);
        if (!\is_string($method)) {
            return null;
        }

        return new PhpMethodCall(
            $receiver,
            $method,
            $call->getStartPosition(),
            $this->arguments($call->argumentExpressionList->children ?? [], $source),
        );
    }

    /**
     * @param array<Node|Token> $children
     *
     * @return list<PhpArgument>
     */
    private function arguments(array $children, string $source): array
    {
        $arguments = [];
        foreach ($children as $child) {
            if (!$child instanceof ArgumentExpression) {
                continue;
            }

            $name = $child->name?->getText($source);
            $arguments[] = new PhpArgument(
                \is_string($name) ? $name : null,
                $child->expression instanceof StringLiteral ? $this->stringLiteral($child->expression, $source) : null,
            );
        }

        return $arguments;
    }

    private function attributeName(Node|Token $name, string $source): string
    {
        if ($name instanceof QualifiedName) {
            $resolvedName = $name->getResolvedName();
            if (null !== $resolvedName) {
                return (string) $resolvedName;
            }
        }

        $text = $name->getText($source);

        return \is_string($text) ? ltrim($text, '\\') : '';
    }

    private function stringLiteral(StringLiteral $literal, string $source): ?PhpStringLiteral
    {
        $children = \is_array($literal->children) ? $literal->children : [$literal->children];
        foreach ($children as $child) {
            if (!$child instanceof Token) {
                return null;
            }
        }

        $start = $literal->getStartPosition();
        $end = $literal->getEndPosition();
        $text = substr($source, $start, $end - $start);
        if (\strlen($text) < 2 || !\in_array($text[0], ["'", '"'], true) || !str_ends_with($text, $text[0])) {
            return null;
        }

        return new PhpStringLiteral(substr($text, 1, -1), $start + 1, $end - 1);
    }
}
