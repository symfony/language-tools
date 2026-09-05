<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node\AnonymousFunctionUseClause;
use Microsoft\PhpParser\Node\DelimitedList\ParameterDeclarationList;
use Microsoft\PhpParser\Node\DelimitedList\UseVariableNameList;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\UseVariableName;

final class PhpLexicalScopeFactBuilder
{
    public function __construct(private readonly TolerantPhpScopeResolver $scopes)
    {
    }

    /** @return list<PhpLexicalScope> */
    public function build(TolerantPhpNodeCollection $collection, string $source): array
    {
        $scopes = [];
        foreach ($collection->lexicalScopes as $node) {
            [, $parent] = $this->scopes->enclosingContext($node);
            $scopes[] = new PhpLexicalScope(
                $node instanceof AnonymousFunctionCreationExpression ? PhpLexicalScopeKind::Closure : PhpLexicalScopeKind::ArrowFunction,
                $node->getStartPosition(),
                $node->getEndPosition(),
                $this->parameterNames($node->parameters, $source),
                $node instanceof AnonymousFunctionCreationExpression ? $this->capturedVariableNames($node->anonymousFunctionUseClause, $source) : [],
                $parent?->getStartPosition(),
                $this->captureComplete($node),
            );
        }

        return $scopes;
    }

    /** @return list<string> */
    private function parameterNames(?ParameterDeclarationList $parameters, string $source): array
    {
        $names = [];
        foreach (null === $parameters ? [] : $parameters->children as $parameter) {
            if (!$parameter instanceof Parameter || null === $name = $this->scopes->variableName($parameter->variableName, $source)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return list<string> */
    private function capturedVariableNames(?AnonymousFunctionUseClause $clause, string $source): array
    {
        if (null === $clause || !$clause->useVariableNameList instanceof UseVariableNameList) {
            return [];
        }
        $names = [];
        foreach ($clause->useVariableNameList->children as $capture) {
            if (!$capture instanceof UseVariableName || null === $name = $this->scopes->variableName($capture->variableName, $source)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    private function captureComplete(AnonymousFunctionCreationExpression|ArrowFunctionCreationExpression $scope): bool
    {
        if ($scope->closeParen instanceof MissingToken) {
            return false;
        }
        if ($scope instanceof ArrowFunctionCreationExpression) {
            return !$scope->arrowToken instanceof MissingToken;
        }
        $use = $scope->anonymousFunctionUseClause;

        return null === $use || (!$use->useVariableNameList instanceof MissingToken && !$use->closeParen instanceof MissingToken);
    }
}
