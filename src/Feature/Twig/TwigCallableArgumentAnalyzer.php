<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;

final class TwigCallableArgumentAnalyzer
{
    private const HASH_KEY = '(?:(?<name>[A-Za-z_][A-Za-z0-9_]*)|\'(?<single>[^\'\\\\]*)\'|"(?<double>[^"\\\\]*)")';

    /**
     * @param string $directive Source of the directive being edited, from its opening marker to the cursor
     *
     * @return array{kind: TwigCallableKind, prefix: string}|null
     */
    public function callableNameCompletion(string $directive): ?array
    {
        $syntax = DelimiterScanner::maskStrings($directive, twig: true);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $syntax, $matches)) {
            return ['kind' => TwigCallableKind::Filter, 'prefix' => $matches[1] ?? ''];
        }
        if (1 === preg_match('/(?<![\w.\'"|])([A-Za-z_][A-Za-z0-9_]*)$/', $syntax, $matches, \PREG_OFFSET_CAPTURE)) {
            if ($this->isMacroDeclaration($syntax, $matches[1][1])) {
                return null;
            }

            return ['kind' => TwigCallableKind::Function, 'prefix' => $matches[1][0]];
        }

        return null;
    }

    /**
     * @param string $directive Source of the directive being edited, from its opening marker to the cursor
     * @param int    $start     Byte offset of that opening marker in the document
     */
    public function incompleteCall(string $directive, int $start): ?TwigCallableCall
    {
        $state = DelimiterScanner::state($directive, twig: true);
        $open = $state->innermostDelimiter();
        if (null !== $state->openString || null === $open || '(' !== $open->delimiter) {
            return null;
        }
        $callable = $this->callableAt($directive, $open->offset);
        if (null === $callable) {
            return null;
        }
        $argumentsText = substr($directive, $open->offset + 1);
        $arguments = TwigArgumentParser::parse($argumentsText, $start + $open->offset + 1);
        $current = array_pop($arguments);
        if (null === $current || 1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $current->text, $prefix)) {
            return null;
        }
        $arguments[] = $current;

        return new TwigCallableCall(
            $callable['kind'],
            $callable['callee'],
            $arguments,
            $prefix[1] ?? '',
        );
    }

    /**
     * @param string $directive Source of the directive being edited, from its opening marker to the cursor
     */
    public function hashArgument(string $directive): ?TwigCallableHashArgument
    {
        $state = DelimiterScanner::state($directive, twig: true);
        $open = $state->openDelimiters;
        $innermost = array_key_last($open);
        if (null === $innermost || '{' !== $open[$innermost]->delimiter) {
            return null;
        }
        $hashStart = $open[$innermost]->offset + 1;
        $entries = DelimiterScanner::split(substr($directive, $hashStart), ',', $hashStart, twig: true);
        $current = array_pop($entries);
        if (null === $current) {
            return null;
        }
        $usedKeys = [];
        foreach ($entries as $entry) {
            if (1 === preg_match('/^\s*'.self::HASH_KEY.'\s*:/', $entry->text, $match, \PREG_UNMATCHED_AS_NULL)) {
                $usedKeys[] = $this->hashKey($match);
            }
        }
        $key = null;
        $quote = null;
        if (null === $state->openString) {
            if (1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)?$/D', $current->text, $match)) {
                return null;
            }
            $prefix = $match[1] ?? '';
        } else {
            $quote = $state->openString->quote;
            $prefix = substr($directive, $state->openString->contentOffset);
            $beforeQuote = substr($current->text, 0, $state->openString->contentOffset - 1 - $current->offset);
            if (str_contains($prefix, '\\')) {
                return null;
            }
            if (1 === preg_match('/^\s*'.self::HASH_KEY.'\s*:\s*$/D', $beforeQuote, $match, \PREG_UNMATCHED_AS_NULL)) {
                $key = $this->hashKey($match);
            } elseif ('' !== trim($beforeQuote)) {
                return null;
            }
        }

        $path = [];
        for ($level = $innermost; $level > 0; --$level) {
            $parent = $open[$level - 1];
            $between = substr($directive, $parent->offset + 1, $open[$level]->offset - $parent->offset - 1);
            if ('{' === $parent->delimiter) {
                $segments = DelimiterScanner::split($between, ',', twig: true);
                $segment = array_pop($segments);
                if (null === $segment || 1 !== preg_match('/^\s*'.self::HASH_KEY.'\s*:\s*$/D', $segment->text, $match, \PREG_UNMATCHED_AS_NULL)) {
                    return null;
                }
                array_unshift($path, $this->hashKey($match));
                continue;
            }
            $callable = '(' === $parent->delimiter ? $this->callableAt($directive, $parent->offset) : null;
            if (null === $callable) {
                return null;
            }
            $arguments = TwigArgumentParser::parse($between);
            $argument = array_pop($arguments);
            if (null === $argument || '' !== trim(substr($argument->text, $argument->valueOffset - $argument->offset))) {
                return null;
            }

            return new TwigCallableHashArgument(
                $callable['kind'],
                $callable['callee'],
                null === $argument->name ? \count($arguments) : null,
                $argument->name,
                $path,
                $usedKeys,
                $key,
                $quote,
                $prefix,
            );
        }

        return null;
    }

    /** @param array<int|string, string|null> $match */
    private function hashKey(array $match): string
    {
        return $match['name'] ?? $match['single'] ?? $match['double'] ?? '';
    }

    /** @return array{kind: TwigCallableKind, callee: string}|null */
    private function callableAt(string $text, int $openOffset): ?array
    {
        $head = substr($text, 0, $openOffset);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $match, \PREG_OFFSET_CAPTURE)) {
            return [
                'kind' => TwigCallableKind::Filter,
                'callee' => $match[1][0],
            ];
        }
        if (1 !== preg_match('/(?<![\w.|])([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $match, \PREG_OFFSET_CAPTURE)
            || str_ends_with(rtrim(substr($head, 0, $match[1][1])), '.')
            || $this->isMacroDeclaration($head, $match[1][1])) {
            return null;
        }

        return [
            'kind' => TwigCallableKind::Function,
            'callee' => $match[1][0],
        ];
    }

    private function isMacroDeclaration(string $text, int $nameOffset): bool
    {
        return 1 === preg_match('/\{%\s*[-~]?\s*macro\s+$/', substr($text, 0, $nameOffset));
    }
}
