<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\AttributeGroup;
use Microsoft\PhpParser\Node\ClassBaseClause;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\ConstElement;
use Microsoft\PhpParser\Node\DelimitedList\ArrayElementList;
use Microsoft\PhpParser\Node\DelimitedList\ExpressionList;
use Microsoft\PhpParser\Node\DelimitedList\ParameterDeclarationList;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Node\EnumCaseDeclaration;
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
use Microsoft\PhpParser\TokenKind;

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
        $constantDeclarations = [];
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
            } elseif ($node instanceof ClassConstDeclaration) {
                array_push($constantDeclarations, ...$this->classConstants($node, $source));
            } elseif ($node instanceof EnumCaseDeclaration) {
                $constant = $this->enumCase($node, $source);
                if (null !== $constant) {
                    $constantDeclarations[] = $constant;
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
            $declaration = $this->methodDeclaration($node, $source, $names);
            if (null !== $declaration) {
                $methodDeclarations[] = $declaration;
            }
        }
        $propertyDeclarations = $this->propertyDeclarations($typedVariableNodes, $source, $names);

        return new PhpDocument($attributes, $methodCalls, $typeDeclarations, $diagnostics, $typedVariables, $names, $objectCreations, $methodDeclarations, $constantDeclarations, $propertyDeclarations);
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

    /**
     * @param list<Parameter|PropertyDeclaration> $declarations
     *
     * @return list<PhpPropertyDeclaration>
     */
    private function propertyDeclarations(array $declarations, string $source, PhpNameContext $names): array
    {
        $properties = [];
        foreach ($declarations as $declaration) {
            if ($declaration instanceof Parameter) {
                $method = $declaration->getFirstAncestor(MethodDeclaration::class);
                $methodName = $method instanceof MethodDeclaration && $method->name instanceof Token ? $method->name->getText($source) : null;
                if (!\is_string($methodName) || '__construct' !== strtolower($methodName) || !$declaration->visibilityToken instanceof Token) {
                    continue;
                }
                $owner = $declaration->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, TraitDeclaration::class);
                if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof TraitDeclaration)) {
                    continue;
                }
                $name = $this->variableName($declaration->variableName, $source);
                if (null === $name) {
                    continue;
                }
                $signatureStart = $declaration->variableName->getStartPosition();
                foreach ([$declaration->visibilityToken, $declaration->setVisibilityToken, ...($declaration->modifiers ?? []), $declaration->questionToken, $declaration->typeDeclarationList, $declaration->byRefToken, $declaration->dotDotDotToken] as $part) {
                    if ($part instanceof Node || $part instanceof Token) {
                        $signatureStart = min($signatureStart, $part->getStartPosition());
                    }
                }
                $properties[] = new PhpPropertyDeclaration(
                    (string) $owner->getNamespacedName(),
                    $name,
                    $declaration->variableName->getStartPosition() + 1,
                    $declaration->variableName->getEndPosition(),
                    trim(substr($source, $signatureStart, $declaration->variableName->getEndPosition() - $signatureStart)),
                    $this->description($declaration),
                    $this->resolvedTypes($declaration->typeDeclarationList, $source, $names),
                    $this->propertyVisibility($declaration),
                    true,
                );

                continue;
            }

            $owner = $declaration->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, TraitDeclaration::class);
            if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof TraitDeclaration)) {
                continue;
            }
            $elements = $declaration->propertyElements;
            if (!$elements instanceof ExpressionList) {
                continue;
            }
            $signatureStart = $elements->getStartPosition();
            foreach ([...$declaration->modifiers, $declaration->questionToken, $declaration->typeDeclarationList] as $part) {
                if ($part instanceof Node || $part instanceof Token) {
                    $signatureStart = min($signatureStart, $part->getStartPosition());
                }
            }
            $signaturePrefix = substr($source, $signatureStart, $elements->getStartPosition() - $signatureStart);
            $types = $this->resolvedTypes($declaration->typeDeclarationList, $source, $names);
            $description = $this->description($declaration);
            $visibility = $this->propertyVisibility($declaration);
            foreach ($elements->children as $element) {
                $variable = $element instanceof Variable
                    ? $element
                    : ($element instanceof AssignmentExpression && $element->leftOperand instanceof Variable ? $element->leftOperand : null);
                if (null === $variable || null === $name = $this->variableName($variable, $source)) {
                    continue;
                }
                $properties[] = new PhpPropertyDeclaration(
                    (string) $owner->getNamespacedName(),
                    $name,
                    $variable->getStartPosition() + 1,
                    $variable->getEndPosition(),
                    trim($signaturePrefix.$variable->getText($source)),
                    $description,
                    $types,
                    $visibility,
                    false,
                );
            }
        }

        return $properties;
    }

    private function propertyVisibility(Parameter|PropertyDeclaration $declaration): string
    {
        if ($declaration instanceof Parameter) {
            return match ($declaration->visibilityToken?->kind) {
                TokenKind::PrivateKeyword => 'private',
                TokenKind::ProtectedKeyword => 'protected',
                default => 'public',
            };
        }

        return match (true) {
            $declaration->hasModifier(TokenKind::PrivateKeyword) => 'private',
            $declaration->hasModifier(TokenKind::ProtectedKeyword) => 'protected',
            default => 'public',
        };
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
        $kind = match (true) {
            $declaration instanceof ClassDeclaration => PhpTypeKind::Class_,
            $declaration instanceof InterfaceDeclaration => PhpTypeKind::Interface_,
            $declaration instanceof TraitDeclaration => PhpTypeKind::Trait_,
            default => PhpTypeKind::Enum,
        };
        $keyword = match ($kind) {
            PhpTypeKind::Class_ => $declaration->classKeyword,
            PhpTypeKind::Interface_ => $declaration->interfaceKeyword,
            PhpTypeKind::Trait_ => $declaration->traitKeyword,
            PhpTypeKind::Enum => $declaration->enumKeyword,
        };
        $start = $keyword->getStartPosition();
        $modifiers = get_object_vars($declaration)['modifiers'] ?? [];
        foreach (\is_array($modifiers) ? $modifiers : [] as $modifier) {
            if ($modifier instanceof Token) {
                $start = min($start, $modifier->getStartPosition());
            }
        }
        $primaryModifier = get_object_vars($declaration)['abstractOrFinalModifier'] ?? null;
        if ($primaryModifier instanceof Token) {
            $start = min($start, $primaryModifier->getStartPosition());
        }
        $members = match ($kind) {
            PhpTypeKind::Class_ => $declaration->classMembers,
            PhpTypeKind::Interface_ => $declaration->interfaceMembers,
            PhpTypeKind::Trait_ => $declaration->traitMembers,
            PhpTypeKind::Enum => $declaration->enumMembers,
        };

        return new PhpTypeDeclaration(
            (string) $declaration->getNamespacedName(),
            null === $parentClassName ? null : (string) $parentClassName,
            $declaration->name->getStartPosition(),
            $declaration->name->getEndPosition(),
            $declaration->getStartPosition(),
            $declaration->getEndPosition(),
            $kind,
            trim(substr($source, $start, $members->getStartPosition() - $start)),
            $this->description($declaration),
        );
    }

    /** @return list<PhpConstantDeclaration> */
    private function classConstants(ClassConstDeclaration $declaration, string $source): array
    {
        $owner = $declaration->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, InterfaceDeclaration::class, TraitDeclaration::class, EnumDeclaration::class);
        if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof InterfaceDeclaration && !$owner instanceof TraitDeclaration && !$owner instanceof EnumDeclaration)) {
            return [];
        }
        $start = $declaration->constKeyword->getStartPosition();
        foreach ($declaration->modifiers as $modifier) {
            $start = min($start, $modifier->getStartPosition());
        }
        $prefix = substr($source, $start, $declaration->constElements->getStartPosition() - $start);
        $public = !$declaration->hasModifier(TokenKind::ProtectedKeyword) && !$declaration->hasModifier(TokenKind::PrivateKeyword);
        $constants = [];
        foreach ($declaration->constElements->children as $element) {
            if (!$element instanceof ConstElement) {
                continue;
            }
            $name = $element->name->getText($source);
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $constants[] = new PhpConstantDeclaration(
                PhpConstantKind::ClassConstant,
                (string) $owner->getNamespacedName(),
                $name,
                $element->name->getStartPosition(),
                $element->name->getEndPosition(),
                trim($prefix.$name).';',
                $this->description($declaration),
                $public,
            );
        }

        return $constants;
    }

    private function enumCase(EnumCaseDeclaration $declaration, string $source): ?PhpConstantDeclaration
    {
        $owner = $declaration->getFirstAncestor(EnumDeclaration::class);
        $name = $declaration->name->getText($source);
        if (!$owner instanceof EnumDeclaration || !\is_string($name) || '' === $name) {
            return null;
        }

        return new PhpConstantDeclaration(
            PhpConstantKind::EnumCase,
            (string) $owner->getNamespacedName(),
            $name,
            $declaration->name->getStartPosition(),
            $declaration->name->getEndPosition(),
            'case '.$name.';',
            $this->description($declaration),
            true,
        );
    }

    private function description(Node $node): ?string
    {
        $comment = $node->getDocCommentText();
        if (null === $comment) {
            return null;
        }
        $description = [];
        foreach (explode("\n", trim($comment, "\r\n")) as $part) {
            $part = trim($part, "*\r\t /");
            if ('' === $part) {
                continue;
            }
            if ('@' === $part[0]) {
                break;
            }
            $description[] = $part;
        }
        $description = implode(' ', $description);

        return '' === $description ? null : $description;
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

    private function methodDeclaration(MethodDeclaration $declaration, string $source, PhpNameContext $names): ?PhpMethodDeclaration
    {
        $owner = $declaration->getFirstAncestor(ClassDeclaration::class, TraitDeclaration::class);
        $nameToken = $declaration->name;
        if ((!$owner instanceof ClassDeclaration && !$owner instanceof TraitDeclaration) || !$nameToken instanceof Token) {
            return null;
        }
        $name = $nameToken->getText($source);
        if (!\is_string($name) || '' === $name) {
            return null;
        }
        $body = get_object_vars($declaration)['compoundStatementOrSemicolon'] ?? null;
        $signatureEnd = $body instanceof Node || $body instanceof Token ? $body->getStartPosition() : $declaration->getEndPosition();
        $signatureStart = $declaration->functionKeyword->getStartPosition();
        foreach ($declaration->modifiers as $modifier) {
            $signatureStart = min($signatureStart, $modifier->getStartPosition());
        }
        $signature = trim(substr($source, $signatureStart, $signatureEnd - $signatureStart));
        $description = trim($declaration->getDescriptionFormatted());
        $attributes = [];
        foreach ($declaration->attributes ?? [] as $group) {
            foreach ($group->attributes->children as $attribute) {
                if ($attribute instanceof Attribute) {
                    $attributes[] = $this->attribute($attribute, $source);
                }
            }
        }
        $parameters = [];
        $parameterList = get_object_vars($declaration)['parameters'] ?? null;
        if ($parameterList instanceof ParameterDeclarationList) {
            foreach ($parameterList->children as $parameter) {
                if ($parameter instanceof Parameter) {
                    $parameters[] = $parameter;
                }
            }
        }
        $firstParameterType = null;
        $firstTypeDeclaration = $parameters[0]->typeDeclarationList ?? null;
        if ($firstTypeDeclaration instanceof QualifiedNameList && 1 !== preg_match('/[|&()]/', $firstTypeDeclaration->getText($source))) {
            $firstParameterTypes = $this->resolvedTypes($firstTypeDeclaration, $source, $names);
            $firstParameterType = 1 === \count($firstParameterTypes) ? $firstParameterTypes[0] : null;
        }

        return new PhpMethodDeclaration(
            (string) $owner->getNamespacedName(),
            $name,
            $nameToken->getStartPosition(),
            $nameToken->getEndPosition(),
            $signature,
            '' === $description ? null : $description,
            $attributes,
            $firstParameterType,
            isset($parameters[0]) && $parameters[0]->dotDotDotToken instanceof Token,
            array_any($parameters, static fn (Parameter $parameter): bool => $parameter->dotDotDotToken instanceof Token),
            !$declaration->hasModifier(TokenKind::ProtectedKeyword) && !$declaration->hasModifier(TokenKind::PrivateKeyword),
        );
    }

    private function attribute(Attribute $attribute, string $source): PhpAttribute
    {
        $group = $attribute->getFirstAncestor(AttributeGroup::class);

        return new PhpAttribute(
            $this->attributeName($attribute->name, $source),
            $this->arguments($attribute->argumentExpressionList->children ?? [], $source),
            $group instanceof AttributeGroup ? $group->getStartPosition() : $attribute->getStartPosition(),
            $group instanceof AttributeGroup ? $group->getEndPosition() : $attribute->getEndPosition(),
            $attribute->name->getStartPosition(),
            $attribute->name->getEndPosition(),
            $this->attributeTargets($attribute, $source),
        );
    }

    /** @return list<PhpAttributeTarget> */
    private function attributeTargets(Attribute $attribute, string $source): array
    {
        $group = $attribute->getFirstAncestor(AttributeGroup::class);
        $declaration = $group?->getParent();
        if (null === $declaration) {
            return [];
        }
        if ($declaration instanceof ClassDeclaration || $declaration instanceof InterfaceDeclaration || $declaration instanceof TraitDeclaration || $declaration instanceof EnumDeclaration) {
            return [new PhpAttributeTarget(
                PhpAttributeTargetKind::Type,
                (string) $declaration->getNamespacedName(),
                null,
                $declaration->name->getStartPosition(),
                $declaration->name->getEndPosition(),
            )];
        }

        $owner = $declaration->getFirstAncestor(
            ObjectCreationExpression::class,
            ClassDeclaration::class,
            InterfaceDeclaration::class,
            TraitDeclaration::class,
            EnumDeclaration::class,
        );
        if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof InterfaceDeclaration && !$owner instanceof TraitDeclaration && !$owner instanceof EnumDeclaration)) {
            return [];
        }
        if ($declaration instanceof MethodDeclaration) {
            $nameToken = $declaration->name;
            if (!$nameToken instanceof Token) {
                return [];
            }
            $name = $nameToken->getText($source);
            if (!\is_string($name) || '' === $name) {
                return [];
            }

            return [new PhpAttributeTarget(
                PhpAttributeTargetKind::Method,
                (string) $owner->getNamespacedName(),
                $name,
                $nameToken->getStartPosition(),
                $nameToken->getEndPosition(),
            )];
        }
        if (!$declaration instanceof PropertyDeclaration) {
            return [];
        }

        $elements = $declaration->propertyElements;
        if (!$elements instanceof ExpressionList) {
            return [];
        }
        $targets = [];
        foreach ($elements->children as $element) {
            $variable = $element instanceof Variable
                ? $element
                : ($element instanceof AssignmentExpression && $element->leftOperand instanceof Variable ? $element->leftOperand : null);
            if (null === $variable || null === $name = $this->variableName($variable, $source)) {
                continue;
            }
            $targets[] = new PhpAttributeTarget(
                PhpAttributeTargetKind::Property,
                (string) $owner->getNamespacedName(),
                $name,
                $variable->getStartPosition() + 1,
                $variable->getEndPosition(),
            );
        }

        return $targets;
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
            $expression = $child->expression?->getText($source);
            $arguments[] = new PhpArgument(
                \is_string($name) ? $name : null,
                $child->expression instanceof StringLiteral ? $this->stringLiteral($child->expression, $source) : null,
                null === $names ? null : $this->phpCallable($child->expression, $source, $names, $owner),
                \is_string($expression) ? $expression : null,
                $child->getStartPosition(),
                $child->getEndPosition(),
                $child->expression?->getStartPosition(),
                $child->expression?->getEndPosition(),
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

        return new PhpStringLiteral(PhpStringLiteralDecoder::decode($text[0], substr($text, 1, -1)), $start + 1, $end - 1);
    }
}
