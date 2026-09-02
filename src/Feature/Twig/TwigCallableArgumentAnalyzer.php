<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Parser\Twig\TwigArgumentParser;

final class TwigCallableArgumentAnalyzer
{
    public function __construct(private readonly TwigArgumentParser $argumentParser)
    {
    }

    /** @return array{kind: TwigCallableKind, prefix: string}|null */
    public function callableNameCompletion(string $before): ?array
    {
        $syntax = $this->maskStringContents($before);
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

    public function incompleteCall(string $before): ?TwigCallableCall
    {
        [$calls, $stack, $quote] = $this->scan($before);
        unset($calls);
        if (null !== $quote || [] === $stack) {
            return null;
        }
        $open = $stack[array_key_last($stack)];
        if ('(' !== $open['delimiter'] || null === $open['callable']) {
            return null;
        }
        $argumentsText = substr($before, $open['offset'] + 1);
        $arguments = $this->argumentParser->parse($argumentsText, $open['offset'] + 1);
        $current = array_pop($arguments);
        if (null === $current || 1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $current->text, $prefix)) {
            return null;
        }
        $arguments[] = $current;

        return new TwigCallableCall(
            $open['callable']['kind'],
            $open['callable']['callee'],
            $open['callable']['calleeOffset'],
            $open['offset'] + 1,
            $arguments,
            $prefix[1] ?? '',
        );
    }

    /** @return list<TwigCallableCall> */
    public function completeCalls(string $text): array
    {
        [$calls] = $this->scan($text);

        return $calls;
    }

    /**
     * @return array{
     *     list<TwigCallableCall>,
     *     list<array{delimiter: string, offset: int, callable: array{kind: TwigCallableKind, callee: string, calleeOffset: int}|null}>,
     *     string|null
     * }
     */
    private function scan(string $text): array
    {
        $calls = [];
        $stack = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
                continue;
            }
            if (\in_array($character, ['(', '[', '{'], true)) {
                $stack[] = [
                    'delimiter' => $character,
                    'offset' => $offset,
                    'callable' => '(' === $character ? $this->callableAt($text, $offset) : null,
                ];
                continue;
            }
            if ([] === $stack || $character !== ['(' => ')', '[' => ']', '{' => '}'][$stack[array_key_last($stack)]['delimiter']]) {
                continue;
            }
            $open = array_pop($stack);
            if (')' !== $character || null === $open['callable']) {
                continue;
            }
            $argumentsOffset = $open['offset'] + 1;
            $calls[] = new TwigCallableCall(
                $open['callable']['kind'],
                $open['callable']['callee'],
                $open['callable']['calleeOffset'],
                $argumentsOffset,
                $this->argumentParser->parse(substr($text, $argumentsOffset, $offset - $argumentsOffset), $argumentsOffset),
            );
        }

        return [$calls, $stack, $quote];
    }

    /** @return array{kind: TwigCallableKind, callee: string, calleeOffset: int}|null */
    private function callableAt(string $text, int $openOffset): ?array
    {
        $head = substr($text, 0, $openOffset);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $match, \PREG_OFFSET_CAPTURE)) {
            return [
                'kind' => TwigCallableKind::Filter,
                'callee' => $match[1][0],
                'calleeOffset' => $match[1][1],
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
            'calleeOffset' => $match[1][1],
        ];
    }

    private function isMacroDeclaration(string $text, int $nameOffset): bool
    {
        return 1 === preg_match('/\{%\s*[-~]?\s*macro\s+$/', substr($text, 0, $nameOffset));
    }

    private function maskStringContents(string $text): string
    {
        $masked = $text;
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null === $quote) {
                if (\in_array($character, ["'", '"'], true)) {
                    $quote = $character;
                }
                continue;
            }
            if ($escaped) {
                $escaped = false;
            } elseif ('\\' === $character) {
                $escaped = true;
            } elseif ($quote === $character) {
                $quote = null;
                continue;
            }
            if ("\n" !== $character) {
                $masked[$offset] = ' ';
            }
        }

        return $masked;
    }
}
