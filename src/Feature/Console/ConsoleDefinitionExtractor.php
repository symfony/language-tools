<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpExpressionParser;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class ConsoleDefinitionExtractor
{
    public function __construct(
        private readonly PhpExpressionParser $expressionParser,
        private readonly BalancedDelimiterMatcher $delimiters,
    ) {
    }

    /** @return array{list<string>, list<string>, bool} */
    public function extract(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $arguments = [];
        $options = [];
        $complete = true;
        foreach ($php->methodCalls as $call) {
            $receiver = substr($text, $call->receiverContext->startOffset, $call->receiverContext->endOffset - $call->receiverContext->startOffset);
            if ($type->name !== $call->className || 'configure' !== $call->enclosingMethod || !$this->isDefinitionReceiver($receiver)) {
                continue;
            }
            if ('addArgument' === $call->method || 'addOption' === $call->method) {
                $name = $call->positionalArgument(0)?->stringLiteral?->value;
                if (null === $name) {
                    $complete = false;
                    continue;
                }
                if ('addArgument' === $call->method) {
                    $arguments[] = $name;
                } else {
                    $options[] = $name;
                }
                continue;
            }
            if ('setDefinition' !== $call->method) {
                continue;
            }
            $expression = $call->positionalArgument(0)?->expression;
            if (null === $expression) {
                $complete = false;
                continue;
            }
            [$definitionArguments, $definitionOptions, $definitionComplete] = $this->setDefinition($expression);
            $arguments = [...$arguments, ...$definitionArguments];
            $options = [...$options, ...$definitionOptions];
            $complete = $complete && $definitionComplete;
        }
        foreach ($this->methodBodyRanges($text, $type, 'configure') as $range) {
            if (!$range['closed']) {
                $complete = false;
            }
        }

        return [$arguments, $options, $complete];
    }

    private function isDefinitionReceiver(string $receiver): bool
    {
        $receiver = preg_replace('/\s+/', '', $receiver);

        return '$this' === $receiver
            || (\is_string($receiver) && 1 === preg_match('/^\$this->(?:addArgument|addOption|setDefinition)\s*\(/', $receiver));
    }

    /** @return array{list<string>, list<string>, bool} */
    private function setDefinition(string $expression): array
    {
        $document = $this->expressionParser->parse($expression);
        $arguments = [];
        $options = [];
        $complete = 1 !== preg_match('/\$|\.\.\./', $expression);
        $recognized = false;
        foreach ($document->objectCreations as $creation) {
            $shortName = substr($creation->className, (int) strrpos('\\'.$creation->className, '\\'));
            if ('InputDefinition' === $shortName) {
                $recognized = true;
                continue;
            }
            if (!\in_array($shortName, ['InputArgument', 'InputOption'], true)) {
                $complete = false;
                continue;
            }
            $recognized = true;
            $name = $creation->positionalArgument(0)?->stringLiteral?->value;
            if (null === $name) {
                $complete = false;
                continue;
            }
            if ('InputArgument' === $shortName) {
                $arguments[] = $name;
            } else {
                $options[] = $name;
            }
        }
        if (!$recognized && 1 !== preg_match('/^\s*\[.*\]\s*$/s', $expression)) {
            $complete = false;
        }
        if (preg_match('/(?<!new\s)\b[A-Za-z_][A-Za-z0-9_]*\s*\(/', $expression)) {
            $complete = false;
        }

        return [array_values(array_unique($arguments)), array_values(array_unique($options)), $complete];
    }

    /** @return list<array{start: int, end: int, closed: bool}> */
    private function methodBodyRanges(string $text, PhpTypeDeclaration $type, string $method): array
    {
        $source = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        preg_match_all('/\bfunction\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^\{;]+)?\s*\{/s', $source, $matches, \PREG_OFFSET_CAPTURE);
        $ranges = [];
        foreach ($matches[0] as [$matched, $relativeOffset]) {
            $open = $type->startOffset + $relativeOffset + strrpos($matched, '{');
            $close = $this->delimiters->matching($text, $open, '{', '}');
            $ranges[] = ['start' => $open, 'end' => $close ?? $type->endOffset, 'closed' => null !== $close];
        }

        return $ranges;
    }
}
