<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\AttributeGroup;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\EnumCaseDeclaration;
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

    /** @var list<MethodDeclaration> */
    public readonly array $methodDeclarations;

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

    /** @param iterable<Node> $descendants */
    public function __construct(iterable $descendants, string $source)
    {
        $attributes = [];
        $methodCalls = [];
        $typedVariableDeclarations = [];
        $objectCreations = [];
        $methodDeclarations = [];
        $classReferences = [];
        $constantDeclarations = [];
        $typeDeclarations = [];
        $nameContextNodes = [];
        $methodAttributes = [];

        foreach ($descendants as $node) {
            if ($node instanceof Attribute) {
                $attributes[] = $node;
                $group = $node->getFirstAncestor(AttributeGroup::class);
                $declaration = $group?->getParent();
                if ($declaration instanceof MethodDeclaration) {
                    $methodAttributes[spl_object_id($declaration)][] = $node;
                }
            } elseif ($node instanceof CallExpression && $node->callableExpression instanceof MemberAccessExpression) {
                $methodCalls[] = $node;
            } elseif ($node instanceof Parameter || $node instanceof PropertyDeclaration) {
                $typedVariableDeclarations[] = $node;
            } elseif ($node instanceof ObjectCreationExpression) {
                $objectCreations[] = $node;
            } elseif ($node instanceof MethodDeclaration) {
                $methodDeclarations[] = $node;
            } elseif ($node instanceof ScopedPropertyAccessExpression && 'class' === $node->memberName->getText($source)) {
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
        $this->methodDeclarations = $methodDeclarations;
        $this->classReferences = $classReferences;
        $this->constantDeclarations = $constantDeclarations;
        $this->typeDeclarations = $typeDeclarations;
        $this->nameContextNodes = $nameContextNodes;
        $this->methodAttributes = $methodAttributes;
    }
}
