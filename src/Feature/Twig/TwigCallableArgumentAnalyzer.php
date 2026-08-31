<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableArgumentAnalyzer
{
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
        $arguments = $this->arguments($argumentsText, $open['offset'] + 1);
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
            $open['callable']['hasNestedParentheses'],
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
     *     list<array{delimiter: string, offset: int, callable: array{kind: TwigCallableKind, callee: string, calleeOffset: int, hasNestedParentheses: bool}|null}>,
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
                if ('(' === $character) {
                    foreach ($stack as &$entry) {
                        if (null !== $entry['callable']) {
                            $entry['callable']['hasNestedParentheses'] = true;
                        }
                    }
                    unset($entry);
                }
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
                $this->arguments(substr($text, $argumentsOffset, $offset - $argumentsOffset), $argumentsOffset),
                hasNestedParentheses: $open['callable']['hasNestedParentheses'],
            );
        }

        return [$calls, $stack, $quote];
    }

    /** @return array{kind: TwigCallableKind, callee: string, calleeOffset: int, hasNestedParentheses: bool}|null */
    private function callableAt(string $text, int $openOffset): ?array
    {
        $head = substr($text, 0, $openOffset);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $match, \PREG_OFFSET_CAPTURE)) {
            return [
                'kind' => TwigCallableKind::Filter,
                'callee' => $match[1][0],
                'calleeOffset' => $match[1][1],
                'hasNestedParentheses' => false,
            ];
        }
        if (1 !== preg_match('/(?<![\w.|])([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $match, \PREG_OFFSET_CAPTURE)
            || $this->isMacroDeclaration($head, $match[1][1])) {
            return null;
        }

        return [
            'kind' => TwigCallableKind::Function,
            'callee' => $match[1][0],
            'calleeOffset' => $match[1][1],
            'hasNestedParentheses' => false,
        ];
    }

    private function isMacroDeclaration(string $text, int $nameOffset): bool
    {
        return 1 === preg_match('/\{%\s*[-~]?\s*macro\s+$/', substr($text, 0, $nameOffset));
    }

    /** @return list<TwigCallableArgument> */
    private function arguments(string $text, int $baseOffset): array
    {
        $segments = [];
        $start = 0;
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
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $stack && $character === $stack[array_key_last($stack)]) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $segments[] = $this->argument(substr($text, $start, $offset - $start), $baseOffset + $start);
                $start = $offset + 1;
            }
        }
        $segments[] = $this->argument(substr($text, $start), $baseOffset + $start);

        return $segments;
    }

    private function argument(string $text, int $offset): TwigCallableArgument
    {
        if (1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:=](?![=>])/', $text, $match, \PREG_OFFSET_CAPTURE)) {
            return new TwigCallableArgument($text, $offset);
        }

        return new TwigCallableArgument($text, $offset, $match[1][0], $offset + $match[1][1]);
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
