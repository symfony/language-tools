<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\AttributeGroup;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\EnumCaseDeclaration;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Node\TraitUseClause;

final class TolerantPhpNodeCollection
{
    /** @var list<Attribute> */
    public readonly array $attributes;

    /** @var list<CallExpression> */
    public readonly array $methodCalls;

    /** @var list<Parameter|PropertyDeclaration> */
    public readonly array $typedVariableDeclarations;

    /** @var list<ObjectCreationExpression> */
    public readonly array $objectCreations;

    /** @var list<ArrayCreationExpression> */
    public readonly array $literalArrays;

    /** @var list<MethodDeclaration> */
    public readonly array $methodDeclarations;

    /** @var list<AnonymousFunctionCreationExpression|ArrowFunctionCreationExpression> */
    public readonly array $lexicalScopes;

    /** @var list<ScopedPropertyAccessExpression> */
    public readonly array $classReferences;

    /** @var list<ClassConstDeclaration|EnumCaseDeclaration> */
    public readonly array $constantDeclarations;

    /** @var list<ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration> */
    public readonly array $typeDeclarations;

    /** @var list<NamespaceDefinition|NamespaceUseDeclaration> */
    public readonly array $nameContextNodes;

    /** @var array<int, list<Attribute>> */
    public readonly array $methodAttributes;

    /** @var array<int, list<Attribute>> */
    public readonly array $parameterAttributes;

    /** @var array<int, list<TraitUseClause>> */
    public readonly array $typeTraitUses;

    /** @param iterable<Node> $descendants */
    public function __construct(iterable $descendants, string $source)
    {
        $attributes = [];
        $methodCalls = [];
        $typedVariableDeclarations = [];
        $objectCreations = [];
        $literalArrays = [];
        $methodDeclarations = [];
        $lexicalScopes = [];
        $classReferences = [];
        $constantDeclarations = [];
        $typeDeclarations = [];
        $nameContextNodes = [];
        $methodAttributes = [];
        $parameterAttributes = [];
        $typeTraitUses = [];

        foreach ($descendants as $node) {
            if ($node instanceof Attribute) {
                $attributes[] = $node;
                $group = $node->getFirstAncestor(AttributeGroup::class);
                $declaration = $group?->getParent();
                if ($declaration instanceof MethodDeclaration) {
                    $methodAttributes[spl_object_id($declaration)][] = $node;
                } elseif ($declaration instanceof Parameter) {
                    $parameterAttributes[spl_object_id($declaration)][] = $node;
                }
            } elseif ($node instanceof TraitUseClause) {
                $owner = $node->getFirstAncestor(ObjectCreationExpression::class, ClassDeclaration::class, TraitDeclaration::class, EnumDeclaration::class);
                if ($owner instanceof ClassDeclaration || $owner instanceof TraitDeclaration || $owner instanceof EnumDeclaration) {
                    $typeTraitUses[spl_object_id($owner)][] = $node;
                }
            } elseif ($node instanceof CallExpression && $node->callableExpression instanceof MemberAccessExpression) {
                $methodCalls[] = $node;
            } elseif ($node instanceof Parameter || $node instanceof PropertyDeclaration) {
                $typedVariableDeclarations[] = $node;
            } elseif ($node instanceof ObjectCreationExpression) {
                $objectCreations[] = $node;
            } elseif ($node instanceof ArrayCreationExpression) {
                $literalArrays[] = $node;
            } elseif ($node instanceof MethodDeclaration) {
                $methodDeclarations[] = $node;
            } elseif ($node instanceof AnonymousFunctionCreationExpression || $node instanceof ArrowFunctionCreationExpression) {
                $lexicalScopes[] = $node;
            } elseif ($node instanceof ScopedPropertyAccessExpression && 0 === strcasecmp('class', (string) $node->memberName->getText($source))) {
                $classReferences[] = $node;
            } elseif ($node instanceof ClassConstDeclaration || $node instanceof EnumCaseDeclaration) {
                $constantDeclarations[] = $node;
            } elseif ($node instanceof ClassDeclaration || $node instanceof InterfaceDeclaration || $node instanceof TraitDeclaration || $node instanceof EnumDeclaration) {
                $typeDeclarations[] = $node;
            }
            if ($node instanceof NamespaceDefinition || $node instanceof NamespaceUseDeclaration) {
                $nameContextNodes[] = $node;
            }
        }

        $this->attributes = $attributes;
        $this->methodCalls = $methodCalls;
        $this->typedVariableDeclarations = $typedVariableDeclarations;
        $this->objectCreations = $objectCreations;
        $this->literalArrays = $literalArrays;
        $this->methodDeclarations = $methodDeclarations;
        $this->lexicalScopes = $lexicalScopes;
        $this->classReferences = $classReferences;
        $this->constantDeclarations = $constantDeclarations;
        $this->typeDeclarations = $typeDeclarations;
        $this->nameContextNodes = $nameContextNodes;
        $this->methodAttributes = $methodAttributes;
        $this->parameterAttributes = $parameterAttributes;
        $this->typeTraitUses = $typeTraitUses;
    }
}
