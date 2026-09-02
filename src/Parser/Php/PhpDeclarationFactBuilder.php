<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\AttributeGroup;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\ConstElement;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Node\EnumCaseDeclaration;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

final class PhpDeclarationFactBuilder
{
    public function __construct(
        private readonly TolerantPhpNodeAdapter $nodes,
        private readonly TolerantPhpScopeResolver $scopes,
        private readonly PhpExpressionFactBuilder $expressions,
    ) {
    }

    public function build(TolerantPhpNodeCollection $collection, string $source, PhpNameContext $names): TolerantPhpDeclarationFacts
    {
        $attributes = [];
        $attributesByNode = [];
        foreach ($collection->attributes as $node) {
            $attribute = $this->attribute($node, $source);
            $attributes[] = $attribute;
            $attributesByNode[spl_object_id($node)] = $attribute;
        }
        $typeDeclarations = [];
        foreach ($collection->typeDeclarations as $node) {
            $typeDeclarations[] = $this->typeDeclaration($node, $source);
        }
        $typedVariables = $this->typedVariables($collection->typedVariableDeclarations, $source, $names);
        $methodDeclarations = [];
        foreach ($collection->methodDeclarations as $node) {
            $methodAttributes = [];
            foreach ($collection->methodAttributes[spl_object_id($node)] ?? [] as $attributeNode) {
                $methodAttributes[] = $attributesByNode[spl_object_id($attributeNode)];
            }
            $declaration = $this->methodDeclaration($node, $source, $names, $methodAttributes);
            if (null !== $declaration) {
                $methodDeclarations[] = $declaration;
            }
        }
        $constantDeclarations = [];
        foreach ($collection->constantDeclarations as $node) {
            if ($node instanceof ClassConstDeclaration) {
                array_push($constantDeclarations, ...$this->classConstants($node, $source));
            } else {
                $constant = $this->enumCase($node, $source);
                if (null !== $constant) {
                    $constantDeclarations[] = $constant;
                }
            }
        }
        $propertyDeclarations = $this->propertyDeclarations($collection->typedVariableDeclarations, $source, $names);

        return new TolerantPhpDeclarationFacts($attributes, $typeDeclarations, $typedVariables, $methodDeclarations, $constantDeclarations, $propertyDeclarations);
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
            [$owner, $scope] = $this->scopes->enclosingContext($declaration);
            $className = null === $owner ? null : (string) $owner->getNamespacedName();
            if ($declaration instanceof Parameter) {
                $name = $this->scopes->variableName($declaration->variableName, $source);
                if (null === $name) {
                    continue;
                }
                $methodName = $scope instanceof MethodDeclaration && $scope->name instanceof Token ? $scope->name->getText($source) : null;
                $promoted = \is_string($methodName) && '__construct' === strtolower($methodName) && $declaration->visibilityToken instanceof Token;
                $variables[] = new PhpTypedVariable(
                    $name,
                    $types,
                    $promoted ? PhpTypedVariableKind::PromotedProperty : PhpTypedVariableKind::Parameter,
                    $className,
                    \is_string($methodName) && '' !== $methodName ? $methodName : null,
                    $scope?->getStartPosition(),
                    $declaration->variableName->getStartPosition() + 1,
                    $declaration->variableName->getEndPosition(),
                );

                continue;
            }
            if (null === $className) {
                continue;
            }
            foreach ($this->nodes->propertyVariables($declaration) as $variable) {
                $name = $this->scopes->variableName($variable, $source);
                if (null === $name) {
                    continue;
                }
                $variables[] = new PhpTypedVariable(
                    $name,
                    $types,
                    PhpTypedVariableKind::Property,
                    $className,
                    null,
                    null,
                    $variable->getStartPosition() + 1,
                    $variable->getEndPosition(),
                );
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
                $name = $this->scopes->variableName($declaration->variableName, $source);
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

            $owner = $declaration->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, InterfaceDeclaration::class, TraitDeclaration::class);
            if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof InterfaceDeclaration && !$owner instanceof TraitDeclaration)) {
                continue;
            }
            $elements = $this->nodes->propertyElements($declaration);
            if (null === $elements) {
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
            foreach ($this->nodes->propertyVariables($declaration) as $variable) {
                $name = $this->scopes->variableName($variable, $source);
                if (null === $name) {
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
            $resolved[] = null === $name ? $names->resolve($this->scopes->qualifiedName($type, $source)) : (string) $name;
        }
        preg_match_all('/(?<![A-Za-z0-9_\\\\])(?:array|bool|callable|false|float|int|iterable|mixed|never|object|string|true|void)(?![A-Za-z0-9_\\\\])/i', $types->getText($source), $builtinTypes);
        foreach ($builtinTypes[0] as $type) {
            $resolved[] = strtolower($type);
        }

        return array_values(array_unique($resolved));
    }

    private function typeDeclaration(ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $declaration, string $source): PhpTypeDeclaration
    {
        $parentClassName = null;
        if ($declaration instanceof ClassDeclaration) {
            $parentClassName = $this->nodes->classBaseClause($declaration)?->baseClass->getResolvedName();
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
        foreach ($this->nodes->typeModifiers($declaration) as $modifier) {
            $start = min($start, $modifier->getStartPosition());
        }
        $primaryModifier = $this->nodes->primaryTypeModifier($declaration);
        if (null !== $primaryModifier) {
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

    /** @param list<PhpAttribute> $attributes */
    private function methodDeclaration(MethodDeclaration $declaration, string $source, PhpNameContext $names, array $attributes): ?PhpMethodDeclaration
    {
        $owner = $declaration->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, InterfaceDeclaration::class, TraitDeclaration::class, EnumDeclaration::class);
        $nameToken = $declaration->name;
        if ($owner instanceof ObjectCreationExpression || (!$owner instanceof ClassDeclaration && !$owner instanceof InterfaceDeclaration && !$owner instanceof TraitDeclaration && !$owner instanceof EnumDeclaration) || !$nameToken instanceof Token) {
            return null;
        }
        $name = $nameToken->getText($source);
        if (!\is_string($name) || '' === $name) {
            return null;
        }
        $body = $this->nodes->methodBody($declaration);
        $signatureEnd = null === $body ? $declaration->getEndPosition() : $body->getStartPosition();
        $signatureStart = $declaration->functionKeyword->getStartPosition();
        foreach ($declaration->modifiers as $modifier) {
            $signatureStart = min($signatureStart, $modifier->getStartPosition());
        }
        $parameters = $this->nodes->methodParameters($declaration);
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
            trim(substr($source, $signatureStart, $signatureEnd - $signatureStart)),
            $this->description($declaration),
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
            $this->expressions->arguments($attribute->argumentExpressionList->children ?? [], $source),
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
        if ($declaration instanceof Parameter) {
            $method = $declaration->getFirstAncestor(MethodDeclaration::class);
            $methodName = $method instanceof MethodDeclaration && $method->name instanceof Token ? $method->name->getText($source) : null;
            $name = $this->scopes->variableName($declaration->variableName, $source);
            if (!\is_string($methodName) || '__construct' !== strtolower($methodName) || !$declaration->visibilityToken instanceof Token || null === $name) {
                return [];
            }

            return [new PhpAttributeTarget(
                PhpAttributeTargetKind::Property,
                (string) $owner->getNamespacedName(),
                $name,
                $declaration->variableName->getStartPosition() + 1,
                $declaration->variableName->getEndPosition(),
            )];
        }
        if (!$declaration instanceof PropertyDeclaration) {
            return [];
        }

        $targets = [];
        foreach ($this->nodes->propertyVariables($declaration) as $variable) {
            $name = $this->scopes->variableName($variable, $source);
            if (null === $name) {
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
}
