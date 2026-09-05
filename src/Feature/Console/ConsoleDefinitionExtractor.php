<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralKind;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class ConsoleDefinitionExtractor
{
    private const INPUT_DEFINITION = 'Symfony\\Component\\Console\\Input\\InputDefinition';
    private const INPUT_ARGUMENT = 'Symfony\\Component\\Console\\Input\\InputArgument';
    private const INPUT_OPTION = 'Symfony\\Component\\Console\\Input\\InputOption';

    public function __construct(
        private readonly BalancedDelimiterMatcher $delimiters,
    ) {
    }

    /** @return array{list<string>, list<string>, bool} */
    public function extract(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $arguments = [];
        $options = [];
        $complete = true;
        $configureRanges = $this->methodBodyRanges($text, $type, 'configure');
        foreach ($php->methodCalls as $call) {
            $nested = 'configure' !== $call->enclosingMethod && $this->isWithinMethod($call->startOffset, $call->endOffset, $configureRanges);
            $receiver = substr($text, $call->receiverContext->startOffset, $call->receiverContext->endOffset - $call->receiverContext->startOffset);
            if ($type->name !== $call->className || ('configure' !== $call->enclosingMethod && !$nested) || !$this->isDefinitionReceiver($receiver)) {
                continue;
            }
            if ($nested) {
                $complete = false;
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
            $argument = $call->positionalArgument(0);
            if (null === $argument) {
                $complete = false;
                continue;
            }
            [$definitionArguments, $definitionOptions, $definitionComplete] = $this->setDefinition($text, $php, $argument);
            $arguments = [...$arguments, ...$definitionArguments];
            $options = [...$options, ...$definitionOptions];
            $complete = $complete && $definitionComplete;
        }
        foreach ($configureRanges as $range) {
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
    private function setDefinition(string $text, PhpDocument $php, PhpArgument $argument): array
    {
        $creations = $php->objectCreationsWithin($argument);
        if (1 !== \count($creations)
            || self::INPUT_DEFINITION !== $creations[0]->className
            || $creations[0]->startOffset !== $argument->expressionStartOffset
            || $creations[0]->endOffset !== $argument->expressionEndOffset
        ) {
            return $this->definitionList($text, $php, $argument);
        }
        $list = $creations[0]->positionalArgument(0);

        return null === $list ? [[], [], true] : $this->definitionList($text, $php, $list);
    }

    /**
     * Reads the names of a literal list of input arguments and options, such
     * as the one `InputDefinition` takes.
     *
     * @return array{list<string>, list<string>, bool}
     */
    private function definitionList(string $text, PhpDocument $php, PhpArgument $list): array
    {
        $creations = $php->objectCreationsWithin($list);
        $arguments = [];
        $options = [];
        $complete = $this->holdsOnlyCreations($text, $list, $creations);
        foreach ($creations as $creation) {
            $name = $creation->positionalArgument(0)?->stringLiteral?->value;
            if (null === $name || !\in_array($creation->className, [self::INPUT_ARGUMENT, self::INPUT_OPTION], true)) {
                $complete = false;
                continue;
            }
            if (self::INPUT_ARGUMENT === $creation->className) {
                $arguments[] = $name;
            } else {
                $options[] = $name;
            }
        }

        return [$arguments, $options, $complete];
    }

    /**
     * Whether the argument is a closed array holding nothing but the given
     * creations: anything else, such as a key, a spread or a variable, hides
     * names the list cannot be read from.
     *
     * @param list<PhpObjectCreation> $creations
     */
    private function holdsOnlyCreations(string $text, PhpArgument $list, array $creations): bool
    {
        $start = $list->expressionStartOffset;
        $end = $list->expressionEndOffset;
        if (PhpLiteralKind::Array !== $list->completeLiteral?->kind || null === $start || null === $end) {
            return false;
        }
        $between = '';
        foreach ($creations as $creation) {
            $between .= substr($text, $start, $creation->startOffset - $start);
            $start = $creation->endOffset;
        }

        return 1 === preg_match('/^[\s\[\],]*$/D', $between.substr($text, $start, $end - $start));
    }

    /** @param list<array{start: int, end: int, closed: bool}> $ranges */
    private function isWithinMethod(int $start, int $end, array $ranges): bool
    {
        return array_any($ranges, static fn (array $range): bool => $start > $range['start'] && $end <= $range['end']);
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
