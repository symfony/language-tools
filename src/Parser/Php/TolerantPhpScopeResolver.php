<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\PropertyHook;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\FunctionDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

final class TolerantPhpScopeResolver
{
    public function __construct(private readonly TolerantPhpNodeAdapter $nodes)
    {
    }

    /**
     * @return array{
     *     ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration|null,
     *     MethodDeclaration|PropertyHook|FunctionDeclaration|AnonymousFunctionCreationExpression|ArrowFunctionCreationExpression|null
     * }
     */
    public function enclosingContext(Node $node): array
    {
        $owner = null;
        $scope = null;
        $anonymousClass = false;
        while (null !== $node = $node->getParent()) {
            if (null === $scope && ($node instanceof MethodDeclaration || $node instanceof PropertyHook || $node instanceof FunctionDeclaration || $node instanceof AnonymousFunctionCreationExpression || $node instanceof ArrowFunctionCreationExpression)) {
                $scope = $node;
            }
            if (null === $owner) {
                if ($node instanceof ObjectCreationExpression && null !== $node->classMembers) {
                    $anonymousClass = true;
                } elseif (!$anonymousClass && ($node instanceof ClassDeclaration || $node instanceof InterfaceDeclaration || $node instanceof TraitDeclaration || $node instanceof EnumDeclaration)) {
                    $owner = $node;
                }
            }
            if (null !== $scope && (null !== $owner || $anonymousClass)) {
                break;
            }
        }

        return [$owner, $scope];
    }

    public function className(QualifiedName $name, string $source, PhpNameContext $names, ?ClassDeclaration $owner = null): ?string
    {
        $text = trim($this->qualifiedName($name, $source), '\\');
        if (\in_array(strtolower($text), ['self', 'static'], true)) {
            return null === $owner ? null : (string) $owner->getNamespacedName();
        }
        if ('parent' === strtolower($text)) {
            $base = null === $owner ? null : $this->nodes->classBaseClause($owner);
            $parent = null === $base ? null : $this->resolvedName($base->baseClass, $source, $names);

            return null === $parent || '' === $parent ? null : $parent;
        }
        $resolved = $this->resolvedName($name, $source, $names);

        return '' === $resolved ? null : $resolved;
    }

    public function classNameFromExpression(mixed $expression, string $source, PhpNameContext $names, ?ClassDeclaration $owner): ?string
    {
        if ($expression instanceof ScopedPropertyAccessExpression) {
            $member = $expression->memberName->getText($source);
            if ('class' !== $member) {
                return null;
            }

            return $this->classNameFromExpression($expression->scopeResolutionQualifier, $source, $names, $owner);
        }
        if ($expression instanceof QualifiedName) {
            return $this->className($expression, $source, $names, $owner);
        }
        if ($expression instanceof Variable) {
            $variable = $expression->getText($source);
            if ('$this' === $variable) {
                return null === $owner ? null : (string) $owner->getNamespacedName();
            }
        }

        return null;
    }

    public function classReferenceName(ScopedPropertyAccessExpression $reference, string $source, PhpNameContext $names): ?string
    {
        $member = $reference->memberName->getText($source);
        $qualifier = $reference->scopeResolutionQualifier;
        if (0 !== strcasecmp('class', (string) $member) || !$qualifier instanceof QualifiedName) {
            return null;
        }
        $text = trim($this->qualifiedName($qualifier, $source), '\\');
        $keyword = strtolower($text);
        if (\in_array($keyword, ['self', 'static'], true)) {
            [$owner] = $this->enclosingContext($reference);
            $className = null === $owner ? null : (string) $owner->getNamespacedName();
        } elseif ('parent' === $keyword) {
            [$owner] = $this->enclosingContext($reference);
            $base = $owner instanceof ClassDeclaration ? $this->nodes->classBaseClause($owner) : null;
            $className = null === $base ? null : $this->resolvedName($base->baseClass, $source, $names);
        } else {
            $className = $this->resolvedName($qualifier, $source, $names);
        }

        return null === $className || '' === $className ? null : ltrim($className, '\\');
    }

    /**
     * Resolves a class-like name against the document name context, which is built once per document.
     *
     * Documents declaring several namespaces need one import table per block, which only the
     * parser knows about, at the price of rebuilding that table for every name it resolves.
     */
    public function resolvedName(QualifiedName $name, string $source, PhpNameContext $names): string
    {
        if (!$names->singleNamespace) {
            $resolved = $name->getResolvedName();
            if (null !== $resolved) {
                return ltrim((string) $resolved, '\\');
            }
        }
        $text = $this->nameParts($name, $source);
        if ($name->globalSpecifier instanceof Token) {
            return $text;
        }
        if ('' === $text) {
            return $names->namespace;
        }
        if (null !== $name->relativeSpecifier) {
            return '' === $names->namespace ? $text : $names->namespace.'\\'.$text;
        }
        $keyword = strtolower($text);
        if (\in_array($keyword, ['self', 'static', 'parent'], true)) {
            return $keyword;
        }

        return $names->resolve($text);
    }

    public function qualifiedName(QualifiedName $name, string $source): string
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

    private function nameParts(QualifiedName $name, string $source): string
    {
        $parts = [];
        foreach ($name->nameParts as $part) {
            if ($part instanceof Token && !$part instanceof MissingToken && TokenKind::Name === $part->kind) {
                $parts[] = (string) $part->getText($source);
            }
        }

        return implode('\\', $parts);
    }

    public function variableName(Node|Token $variable, string $source): ?string
    {
        $name = $variable->getText($source);

        return \is_string($name) && 1 === preg_match('/^\\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) ? substr($name, 1) : null;
    }
}
