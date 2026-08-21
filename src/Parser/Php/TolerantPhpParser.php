<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\ClassBaseClause;
use Microsoft\PhpParser\Node\DelimitedList\ArrayElementList;
use Microsoft\PhpParser\Node\DelimitedList\ExpressionList;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\NamespaceAliasingClause;
use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\NamespaceUseGroupClause;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\PropertyDeclaration;
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
        $typedVariableNodes = [];
        $objectCreationNodes = [];
        $methodDeclarationNodes = [];
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
            } elseif ($node instanceof Parameter || $node instanceof PropertyDeclaration) {
                $typedVariableNodes[] = $node;
            } elseif ($node instanceof ObjectCreationExpression) {
                $objectCreationNodes[] = $node;
            } elseif ($node instanceof MethodDeclaration) {
                $methodDeclarationNodes[] = $node;
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
        $typedVariables = $this->typedVariables($typedVariableNodes, $source, $names);
        $objectCreations = [];
        foreach ($objectCreationNodes as $node) {
            $creation = $this->objectCreation($node, $source, $names);
            if (null !== $creation) {
                $objectCreations[] = $creation;
            }
        }
        $methodDeclarations = [];
        foreach ($methodDeclarationNodes as $node) {
            $declaration = $this->methodDeclaration($node, $source);
            if (null !== $declaration) {
                $methodDeclarations[] = $declaration;
            }
        }

        return new PhpDocument($attributes, $methodCalls, $typeDeclarations, $diagnostics, $typedVariables, $names, $objectCreations, $methodDeclarations);
    }

    /**
     * @param list<Parameter|PropertyDeclaration> $declarations
     *
     * @return list<PhpTypedVariable>
     */
    private function typedVariables(array $declarations, string $source, PhpNameContext $names): array
    {
        $variables = [];
        foreach ($declarations as $declaration) {
            $types = $this->resolvedTypes($declaration->typeDeclarationList ?? null, $source, $names);
            if ([] === $types) {
                continue;
            }
            if ($declaration instanceof Parameter) {
                $name = $this->variableName($declaration->variableName, $source);
                if (null !== $name) {
                    $variables[] = new PhpTypedVariable($name, $types);
                }
                continue;
            }
            $elements = get_object_vars($declaration)['propertyElements'] ?? null;
            if (!$elements instanceof ExpressionList) {
                continue;
            }
            foreach ($elements->children as $element) {
                $variable = $element instanceof Variable
                    ? $element
                    : ($element instanceof AssignmentExpression && $element->leftOperand instanceof Variable ? $element->leftOperand : null);
                if (null !== $variable && null !== $name = $this->variableName($variable, $source)) {
                    $variables[] = new PhpTypedVariable($name, $types);
                }
            }
        }

        return $variables;
    }

    /** @return list<string> */
    private function resolvedTypes(mixed $types, string $source, PhpNameContext $names): array
    {
        if (!$types instanceof QualifiedNameList) {
            return [];
        }
        $resolved = [];
        foreach ($types->getDescendantNodes() as $type) {
            if (!$type instanceof QualifiedName) {
                continue;
            }
            $name = $type->getResolvedName();
            $resolved[] = null === $name ? $names->resolve($this->qualifiedName($type, $source)) : (string) $name;
        }

        return array_values(array_unique($resolved));
    }

    private function variableName(Node|Token $variable, string $source): ?string
    {
        $name = $variable->getText($source);

        return \is_string($name) && 1 === preg_match('/^\\$[A-Za-z_][A-Za-z0-9_]*$/', $name) ? substr($name, 1) : null;
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

    private function objectCreation(ObjectCreationExpression $creation, string $source, PhpNameContext $names): ?PhpObjectCreation
    {
        if (!$creation->classTypeDesignator instanceof QualifiedName) {
            return null;
        }
        $className = $this->className($creation->classTypeDesignator, $source, $names);
        if (null === $className) {
            return null;
        }
        $owner = $creation->getFirstAncestor(ClassDeclaration::class);
        $method = $creation->getFirstAncestor(MethodDeclaration::class);
        $methodName = $method instanceof MethodDeclaration && $method->name instanceof Token ? $method->name->getText($source) : null;

        return new PhpObjectCreation(
            $className,
            $this->arguments(
                $creation->argumentExpressionList->children ?? [],
                $source,
                $names,
                $owner instanceof ClassDeclaration ? $owner : null,
            ),
            \is_string($methodName) && '' !== $methodName ? $methodName : null,
        );
    }

    private function methodDeclaration(MethodDeclaration $declaration, string $source): ?PhpMethodDeclaration
    {
        $owner = $declaration->getFirstAncestor(ClassDeclaration::class);
        $nameToken = $declaration->name;
        if (!$owner instanceof ClassDeclaration || !$nameToken instanceof Token) {
            return null;
        }
        $name = $nameToken->getText($source);
        if (!\is_string($name) || '' === $name) {
            return null;
        }
        $body = get_object_vars($declaration)['compoundStatementOrSemicolon'] ?? null;
        $signatureEnd = $body instanceof Node || $body instanceof Token ? $body->getStartPosition() : $declaration->getEndPosition();
        $signature = trim(substr($source, $declaration->getStartPosition(), $signatureEnd - $declaration->getStartPosition()));
        $description = trim($declaration->getDescriptionFormatted());

        return new PhpMethodDeclaration(
            (string) $owner->getNamespacedName(),
            $name,
            $nameToken->getStartPosition(),
            $nameToken->getEndPosition(),
            $signature,
            '' === $description ? null : $description,
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
    private function arguments(array $children, string $source, ?PhpNameContext $names = null, ?ClassDeclaration $owner = null): array
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
                null === $names ? null : $this->phpCallable($child->expression, $source, $names, $owner),
            );
        }

        return $arguments;
    }

    private function phpCallable(mixed $expression, string $source, PhpNameContext $names, ?ClassDeclaration $owner): ?PhpCallable
    {
        if ($expression instanceof ArrayCreationExpression) {
            $elements = get_object_vars($expression)['arrayElements'] ?? null;
            if (!$elements instanceof ArrayElementList) {
                return null;
            }
            $values = [];
            foreach ($elements->children as $element) {
                if ($element instanceof ArrayElement) {
                    $values[] = $element->elementValue;
                }
            }
            if (2 !== \count($values) || !$values[1] instanceof StringLiteral) {
                return null;
            }

            return $this->callable(
                $this->classNameFromExpression($values[0], $source, $names, $owner),
                $this->stringLiteral($values[1], $source)?->value(),
            );
        }
        if (!$expression instanceof CallExpression || !$this->isFirstClassCallable($expression)) {
            return null;
        }
        $callable = $expression->callableExpression;
        if ($callable instanceof ScopedPropertyAccessExpression) {
            $className = $this->classNameFromExpression($callable->scopeResolutionQualifier, $source, $names, $owner);
            $method = $callable->memberName->getText($source);
        } elseif ($callable instanceof MemberAccessExpression) {
            $className = $this->classNameFromExpression($callable->dereferencableExpression, $source, $names, $owner);
            $method = $callable->memberName->getText($source);
        } else {
            return null;
        }

        return $this->callable($className, \is_string($method) ? $method : null);
    }

    private function callable(?string $className, ?string $method): ?PhpCallable
    {
        if (null === $className || '' === $className || null === $method || 1 !== preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $method)) {
            return null;
        }

        return new PhpCallable($className, $method);
    }

    private function isFirstClassCallable(CallExpression $call): bool
    {
        $arguments = [];
        foreach ($call->argumentExpressionList->children ?? [] as $child) {
            if ($child instanceof ArgumentExpression) {
                $arguments[] = $child;
            }
        }

        return 1 === \count($arguments) && $arguments[0]->dotDotDotToken instanceof Token && null === $arguments[0]->expression;
    }

    private function classNameFromExpression(mixed $expression, string $source, PhpNameContext $names, ?ClassDeclaration $owner): ?string
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

            return null;
        }

        return null;
    }

    private function className(QualifiedName $name, string $source, PhpNameContext $names, ?ClassDeclaration $owner = null): ?string
    {
        $text = trim($this->qualifiedName($name, $source), '\\');
        if (\in_array(strtolower($text), ['self', 'static'], true)) {
            return null === $owner ? null : (string) $owner->getNamespacedName();
        }
        if ('parent' === strtolower($text)) {
            $base = null === $owner ? null : (get_object_vars($owner)['classBaseClause'] ?? null);
            $parent = $base instanceof ClassBaseClause ? $base->baseClass->getResolvedName() : null;

            return null === $parent ? null : (string) $parent;
        }
        $resolved = $name->getResolvedName();
        $resolved = null === $resolved ? $names->resolve($text) : (string) $resolved;

        return '' === $resolved ? null : ltrim($resolved, '\\');
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
