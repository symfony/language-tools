<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\ClassBaseClause;
use Microsoft\PhpParser\Node\DelimitedList\ArrayElementList;
use Microsoft\PhpParser\Node\DelimitedList\ParameterDeclarationList;
use Microsoft\PhpParser\Node\DelimitedList\PropertyElementList;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\NamespaceAliasingClause;
use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\NamespaceUseGroupClause;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\PropertyElement;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Node\TraitUseClause;
use Microsoft\PhpParser\Token;

final class TolerantPhpNodeAdapter
{
    /** @return list<Variable> */
    public function propertyVariables(PropertyDeclaration $declaration): array
    {
        $elements = $this->propertyElements($declaration);
        if (null === $elements) {
            return [];
        }
        $variables = [];
        foreach ($elements->children as $element) {
            if ($element instanceof PropertyElement && $element->variable instanceof Variable) {
                $variables[] = $element->variable;
            }
        }

        return $variables;
    }

    public function propertyElements(PropertyDeclaration $declaration): ?PropertyElementList
    {
        $elements = $this->member($declaration, 'propertyElements');

        return $elements instanceof PropertyElementList ? $elements : null;
    }

    public function namespaceAliasingClause(NamespaceUseClause|NamespaceUseGroupClause $clause): ?NamespaceAliasingClause
    {
        $alias = $this->member($clause, 'namespaceAliasingClause');

        return $alias instanceof NamespaceAliasingClause ? $alias : null;
    }

    public function classBaseClause(ClassDeclaration $declaration): ?ClassBaseClause
    {
        $base = $this->member($declaration, 'classBaseClause');

        return $base instanceof ClassBaseClause ? $base : null;
    }

    /**
     * @param ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $declaration
     *
     * @return list<Token>
     */
    public function typeModifiers(Node $declaration): array
    {
        $modifiers = $this->member($declaration, 'modifiers');
        if (!\is_array($modifiers)) {
            return [];
        }

        return array_values(array_filter($modifiers, static fn (mixed $modifier): bool => $modifier instanceof Token));
    }

    /** @param ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $declaration */
    public function primaryTypeModifier(Node $declaration): ?Token
    {
        $modifier = $this->member($declaration, 'abstractOrFinalModifier');

        return $modifier instanceof Token ? $modifier : null;
    }

    public function methodBody(MethodDeclaration $declaration): Node|Token|null
    {
        $body = $this->member($declaration, 'compoundStatementOrSemicolon');

        return $body instanceof Node || $body instanceof Token ? $body : null;
    }

    /** @return list<Parameter> */
    public function methodParameters(MethodDeclaration $declaration): array
    {
        $parameters = $this->member($declaration, 'parameters');
        if (!$parameters instanceof ParameterDeclarationList) {
            return [];
        }

        return array_values(array_filter($parameters->children, static fn (mixed $parameter): bool => $parameter instanceof Parameter));
    }

    /** @return list<QualifiedName> */
    public function traitUseNames(TraitUseClause $clause): array
    {
        $names = $this->member($clause, 'traitNameList');
        if (!$names instanceof QualifiedNameList) {
            return [];
        }

        return array_values(array_filter($names->children, static fn (mixed $name): bool => $name instanceof QualifiedName));
    }

    /** @return list<QualifiedName> */
    public function typeInterfaceNames(ClassDeclaration|InterfaceDeclaration|EnumDeclaration $declaration): array
    {
        $clause = $this->member($declaration, match (true) {
            $declaration instanceof ClassDeclaration => 'classInterfaceClause',
            $declaration instanceof InterfaceDeclaration => 'interfaceBaseClause',
            default => 'enumInterfaceClause',
        });
        $names = \is_object($clause) ? $this->member($clause, 'interfaceNameList') : null;
        if (!$names instanceof QualifiedNameList) {
            return [];
        }

        return array_values(array_filter($names->children, static fn (mixed $name): bool => $name instanceof QualifiedName));
    }

    /** @return list<ArrayElement> */
    public function arrayElements(ArrayCreationExpression $expression): array
    {
        $elements = $this->member($expression, 'arrayElements');
        if (!$elements instanceof ArrayElementList) {
            return [];
        }

        return array_values(array_filter($elements->children, static fn (mixed $element): bool => $element instanceof ArrayElement));
    }

    private function member(object $node, string $name): mixed
    {
        return get_object_vars($node)[$name] ?? null;
    }
}
