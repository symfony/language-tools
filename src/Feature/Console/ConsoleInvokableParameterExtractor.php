<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class ConsoleInvokableParameterExtractor
{
    private const ARGUMENT_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Argument';
    private const OPTION_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Option';

    public function __construct(private readonly BalancedDelimiterMatcher $delimiters)
    {
    }

    /** @return array{list<string>, list<string>, list<string>, bool} */
    public function extract(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $range = $this->methodParameterRange($text, $type, '__invoke');
        if (null === $range) {
            return [$this->traits($text, $php, $type), [], [], true];
        }
        $parameters = substr($text, $range[0], $range[1] - $range[0]);
        preg_match_all('/#\[\s*(?<attribute>[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b(?<arguments>\s*\((?:[^()\'\"]+|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")*\))?\s*\]\s*(?:(?:public|protected|private|readonly|static)\s+)*(?:[?\\\\A-Za-z_][\\\\A-Za-z0-9_|&?()]*\s+)?\$(?<parameter>[A-Za-z_][A-Za-z0-9_]*)/s', $parameters, $matches, \PREG_SET_ORDER);
        $arguments = [];
        $options = [];
        $complete = true;
        foreach ($matches as $match) {
            $attribute = $php->resolveName($match['attribute']);
            $kind = match ($attribute) {
                self::ARGUMENT_ATTRIBUTE => ConsoleInputKind::Argument,
                self::OPTION_ATTRIBUTE => ConsoleInputKind::Option,
                default => null,
            };
            if (null === $kind) {
                continue;
            }
            $name = $this->attributeInputName($match['arguments'], $match['parameter']);
            if (null === $name) {
                $complete = false;
                continue;
            }
            if (ConsoleInputKind::Argument === $kind) {
                $arguments[] = $name;
            } else {
                $options[] = $name;
            }
        }

        return [$this->traits($text, $php, $type), $arguments, $options, $complete];
    }

    private function attributeInputName(string $arguments, string $parameter): ?string
    {
        if ('' === trim($arguments)) {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter) ?? $parameter);
        }
        $arguments = $this->splitArguments(substr(trim($arguments), 1, -1));
        foreach ($arguments as $argument) {
            if (preg_match('/^\s*name\s*:\s*(.*)$/s', $argument, $match)) {
                return $this->literal($match[1]);
            }
        }
        if (isset($arguments[1])) {
            return $this->literal($arguments[1]);
        }

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter) ?? $parameter);
    }

    /** @return list<string> */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($arguments); $offset < $length; ++$offset) {
            $character = $arguments[$offset];
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
                ++$depth;
            } elseif (\in_array($character, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $character) {
                $parts[] = substr($arguments, $start, $offset - $start);
                $start = $offset + 1;
            }
        }
        $parts[] = substr($arguments, $start);

        return $parts;
    }

    private function literal(string $expression): ?string
    {
        $expression = trim($expression);
        if (\strlen($expression) < 2 || !\in_array($expression[0], ["'", '"'], true) || !str_ends_with($expression, $expression[0])) {
            return null;
        }

        return PhpStringLiteralDecoder::decode($expression[0], substr($expression, 1, -1));
    }

    /** @return list<string> */
    private function traits(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $body = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        preg_match_all('/^\s*use\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:\s*,\s*[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)*)\s*;/m', $body, $matches);
        $traits = [];
        foreach ($matches[1] as $list) {
            foreach (preg_split('/\s*,\s*/', $list) ?: [] as $trait) {
                $traits[] = $php->resolveName($trait);
            }
        }
        $traits = array_values(array_unique($traits));
        sort($traits);

        return $traits;
    }

    /** @return array{int, int}|null */
    private function methodParameterRange(string $text, PhpTypeDeclaration $type, string $method): ?array
    {
        $source = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        if (!preg_match('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open = $type->startOffset + $match[0][1] + strrpos($match[0][0], '(');
        $close = $this->delimiters->matching($text, $open, '(', ')');

        return null === $close ? null : [$open + 1, $close];
    }
}
