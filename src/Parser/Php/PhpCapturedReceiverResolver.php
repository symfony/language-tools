<?php

namespace Symfony\Lsp\Parser\Php;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;

final class PhpCapturedReceiverResolver
{
    public function __construct(private readonly BalancedDelimiterMatcher $delimiters)
    {
    }

    /** @return list<PhpTypedVariable> */
    public function variables(string $source, PhpDocument $php, PhpMethodCall $call): array
    {
        if ([] !== $variables = $php->receiverVariables($call)) {
            return $variables;
        }
        if (PhpMethodReceiverKind::Variable !== $call->receiverContext->kind
            || null === $call->receiverContext->name
            || null === $call->className
            || null === $call->scopeStartOffset
        ) {
            return [];
        }
        $variables = [];
        foreach ($php->typedVariables as $variable) {
            if ($call->receiverContext->name !== $variable->name
                || PhpTypedVariableKind::Parameter !== $variable->kind
                || $call->className !== $variable->className
                || null === $variable->methodName
                || null === $variable->scopeStartOffset
                || !$this->isCapturedFromScope($source, $call, $variable->name, $variable->scopeStartOffset)
            ) {
                continue;
            }
            $variables[] = $variable;
        }

        return $variables;
    }

    public function isCapturedFromScope(string $source, PhpMethodCall $call, string $variable, int $scopeStartOffset): bool
    {
        return $this->scopeContains($source, $scopeStartOffset, $call) && $this->closureCaptures($source, $call, $variable);
    }

    private function scopeContains(string $source, int $scopeStartOffset, PhpMethodCall $call): bool
    {
        $parametersOpen = strpos($source, '(', $scopeStartOffset);
        if (false === $parametersOpen || null === $parametersClose = $this->delimiters->matching($source, $parametersOpen, '(', ')')) {
            return false;
        }
        $open = strpos($source, '{', $parametersClose + 1);
        $semicolon = strpos($source, ';', $parametersClose + 1);
        if (false === $open || (false !== $semicolon && $semicolon < $open) || $call->startOffset <= $open) {
            return false;
        }
        $close = $this->delimiters->matching($source, $open, '{', '}');

        return null !== $close && $call->endOffset <= $close;
    }

    private function closureCaptures(string $source, PhpMethodCall $call, string $variable): bool
    {
        if (null === $call->scopeStartOffset) {
            return false;
        }
        $scope = substr($source, $call->scopeStartOffset, $call->startOffset - $call->scopeStartOffset);
        if (!preg_match('/^(?:static\s+)?(fn|function)\s*&?\s*\(/', $scope, $match)) {
            return false;
        }
        $open = $call->scopeStartOffset + \strlen($match[0]) - 1;
        $close = $this->delimiters->matching($source, $open, '(', ')');
        if (null === $close || $close >= $call->startOffset) {
            return false;
        }
        $parameterList = substr($source, $open + 1, $close - $open - 1);
        if (1 === preg_match('/\$'.preg_quote($variable, '/').'\b/', $parameterList)) {
            return false;
        }
        if ('fn' === $match[1]) {
            return true;
        }
        $afterParameters = substr($source, $close + 1, $call->startOffset - $close - 1);
        if (!preg_match('/^\s*use\s*\(/', $afterParameters, $use)) {
            return false;
        }
        $useOpen = $close + 1 + \strlen($use[0]) - 1;
        $useClose = $this->delimiters->matching($source, $useOpen, '(', ')');
        if (null === $useClose || $useClose >= $call->startOffset) {
            return false;
        }
        $captures = substr($source, $useOpen + 1, $useClose - $useOpen - 1);

        return 1 === preg_match('/(?:^|,)\s*&?\s*\$'.preg_quote($variable, '/').'\s*(?:,|$)/', $captures);
    }
}
