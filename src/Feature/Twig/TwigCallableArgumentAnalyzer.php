<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Parser\Twig\TwigArgumentParser;

final class TwigCallableArgumentAnalyzer
{
    public function __construct(private readonly TwigArgumentParser $argumentParser)
    {
    }

    /**
     * @param string $directive Source of the directive being edited, from its opening marker to the cursor
     *
     * @return array{kind: TwigCallableKind, prefix: string}|null
     */
    public function callableNameCompletion(string $directive): ?array
    {
        $syntax = $this->maskStringContents($directive);
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
        [$stack, $quote] = $this->scan($directive);
        if (null !== $quote || [] === $stack) {
            return null;
        }
        $open = $stack[array_key_last($stack)];
        if ('(' !== $open['delimiter'] || null === $open['callable']) {
            return null;
        }
        $argumentsText = substr($directive, $open['offset'] + 1);
        $arguments = $this->argumentParser->parse($argumentsText, $start + $open['offset'] + 1);
        $current = array_pop($arguments);
        if (null === $current || 1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $current->text, $prefix)) {
            return null;
        }
        $arguments[] = $current;

        return new TwigCallableCall(
            $open['callable']['kind'],
            $open['callable']['callee'],
            $arguments,
            $prefix[1] ?? '',
        );
    }

    /**
     * @return array{
     *     list<array{delimiter: string, offset: int, callable: array{kind: TwigCallableKind, callee: string}|null}>,
     *     string|null
     * }
     */
    private function scan(string $text): array
    {
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
            if ([] !== $stack && $character === ['(' => ')', '[' => ']', '{' => '}'][$stack[array_key_last($stack)]['delimiter']]) {
                array_pop($stack);
            }
        }

        return [$stack, $quote];
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
