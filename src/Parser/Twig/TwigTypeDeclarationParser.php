<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigTypeDeclarationParser
{
    private const SPECIAL_CHARACTERS = [
        'f' => "\f",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'v' => "\v",
    ];

    public function __construct(private readonly TwigCommentParser $commentParser)
    {
    }

    /** @return list<TwigTypeDeclaration> */
    public function parse(string $source): array
    {
        $masked = $this->commentParser->mask($source);
        $declarations = [];
        $length = \strlen($source);
        $offset = 0;
        $verbatim = false;

        while ($offset < $length && false !== $start = strpos($masked, '{', $offset)) {
            if ('{{' === substr($masked, $start, 2)) {
                $end = $this->directiveEnd($masked, $start + 2, '}}');
                $offset = null === $end ? $start + 2 : $end + 2;
                continue;
            }
            if ('{%' !== substr($masked, $start, 2)) {
                $offset = $start + 1;
                continue;
            }

            $end = $this->directiveEnd($masked, $start + 2, '%}');
            if (null === $end) {
                $tag = $this->tag($masked, $start + 2, $length);
                if ('types' === ($tag[0] ?? null)) {
                    array_push($declarations, ...$this->declarations($source, $tag[1], $length));
                    break;
                }
                $offset = $start + 2;
                continue;
            }
            $tag = $this->tag($masked, $start + 2, $end);
            if (null !== $tag) {
                [$name, $bodyStart] = $tag;
                if ($verbatim) {
                    $verbatim = 'endverbatim' !== $name;
                } elseif ('verbatim' === $name) {
                    $verbatim = true;
                } elseif ('types' === $name) {
                    array_push($declarations, ...$this->declarations($source, $bodyStart, $end));
                }
            }
            $offset = $end + 2;
        }

        return $declarations;
    }

    private function directiveEnd(string $source, int $offset, string $delimiter): ?int
    {
        $length = \strlen($source);
        $quote = null;
        $escaped = false;
        $brackets = [];

        for (; $offset < $length; ++$offset) {
            $character = $source[$offset];
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
            if ([] === $brackets && $delimiter === substr($source, $offset, 2)) {
                return $offset;
            }
            if ('\'' === $character || '"' === $character) {
                $quote = $character;
            } elseif ('(' === $character) {
                $brackets[] = ')';
            } elseif ('[' === $character) {
                $brackets[] = ']';
            } elseif ('{' === $character) {
                $brackets[] = '}';
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            }
        }

        return null;
    }

    /** @return array{string, int}|null */
    private function tag(string $source, int $start, int $end): ?array
    {
        $offset = $start;
        while ($offset < $end && ctype_space($source[$offset])) {
            ++$offset;
        }
        if ($offset < $end && ('-' === $source[$offset] || '~' === $source[$offset])) {
            ++$offset;
        }
        while ($offset < $end && ctype_space($source[$offset])) {
            ++$offset;
        }
        if (!preg_match('/[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*/A', $source, $match, 0, $offset)) {
            return null;
        }

        return [$match[0], $offset + \strlen($match[0])];
    }

    /** @return list<TwigTypeDeclaration> */
    private function declarations(string $source, int $start, int $end): array
    {
        $tokens = $this->tokens($source, $start, $end);
        $declarations = [];
        $index = 0;
        if ('{' === ($tokens[0]['value'] ?? null)) {
            ++$index;
        }

        while (isset($tokens[$index])) {
            if ('}' === $tokens[$index]['value']) {
                break;
            }
            if ('name' !== $tokens[$index]['type']) {
                ++$index;
                continue;
            }

            $name = $tokens[$index++];
            $optional = false;
            if ('?' === ($tokens[$index]['value'] ?? null)) {
                $optional = true;
                ++$index;
            }
            if (':' !== ($tokens[$index]['value'] ?? null)) {
                continue;
            }
            ++$index;
            if ('string' !== ($tokens[$index]['type'] ?? null)) {
                continue;
            }
            $type = $tokens[$index++];
            $declarations[] = new TwigTypeDeclaration(
                $name['value'],
                $type['value'],
                $optional,
                $name['documentation'],
            );
        }

        return $declarations;
    }

    /**
     * @return list<array{type: string, value: string, documentation: ?string}>
     */
    private function tokens(string $source, int $start, int $end): array
    {
        $tokens = [];
        $documentation = [];
        $offset = $start;

        while ($offset < $end) {
            if (ctype_space($source[$offset])) {
                ++$offset;
                continue;
            }
            if ('#' === $source[$offset]) {
                $lineEnd = $offset + strcspn($source, "\r\n", $offset);
                if ('#' === ($source[$offset + 1] ?? null)) {
                    $comment = trim(substr($source, $offset + 2, $lineEnd - $offset - 2));
                    if ('' !== $comment) {
                        $documentation[] = $comment;
                    }
                }
                $offset = $lineEnd;
                continue;
            }
            if (preg_match('/[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*/A', $source, $match, 0, $offset)) {
                $tokens[] = $this->token('name', $match[0], $documentation);
                $documentation = [];
                $offset += \strlen($match[0]);
                continue;
            }
            if ('\'' === $source[$offset] || '"' === $source[$offset]) {
                $quote = $source[$offset++];
                $valueStart = $offset;
                $escaped = false;
                while ($offset < $end) {
                    $character = $source[$offset];
                    if ($escaped) {
                        $escaped = false;
                    } elseif ('\\' === $character) {
                        $escaped = true;
                    } elseif ($quote === $character) {
                        break;
                    }
                    ++$offset;
                }
                if ($offset >= $end) {
                    break;
                }
                $tokens[] = $this->token('string', $this->decodeString(substr($source, $valueStart, $offset - $valueStart), $quote), $documentation);
                $documentation = [];
                ++$offset;
                continue;
            }

            $tokens[] = $this->token('punctuation', $source[$offset], $documentation);
            $documentation = [];
            ++$offset;
        }

        return $tokens;
    }

    private function decodeString(string $value, string $quote): string
    {
        $decoded = '';
        $length = \strlen($value);
        for ($offset = 0; $offset < $length; ++$offset) {
            if ('\\' !== $value[$offset] || $offset + 1 >= $length) {
                $decoded .= $value[$offset];
                continue;
            }
            $character = $value[++$offset];
            if (isset(self::SPECIAL_CHARACTERS[$character])) {
                $decoded .= self::SPECIAL_CHARACTERS[$character];
            } elseif ('\\' === $character || $quote === $character) {
                $decoded .= $character;
            } elseif ('#' === $character && '{' === ($value[$offset + 1] ?? null)) {
                $decoded .= '#{';
                ++$offset;
            } elseif ('x' === $character && isset($value[$offset + 1]) && ctype_xdigit($value[$offset + 1])) {
                $hexadecimal = $value[++$offset];
                if (isset($value[$offset + 1]) && ctype_xdigit($value[$offset + 1])) {
                    $hexadecimal .= $value[++$offset];
                }
                /** @var int<0, 255> $codepoint */
                $codepoint = (int) hexdec($hexadecimal);
                $decoded .= \chr($codepoint);
            } elseif (ctype_digit($character) && $character < '8') {
                $octal = $character;
                while (isset($value[$offset + 1]) && ctype_digit($value[$offset + 1]) && $value[$offset + 1] < '8' && \strlen($octal) < 3) {
                    $octal .= $value[++$offset];
                }
                /** @var int<0, 255> $codepoint */
                $codepoint = (int) octdec($octal) % 256;
                $decoded .= \chr($codepoint);
            } else {
                $decoded .= '\\'.$character;
            }
        }

        return $decoded;
    }

    /**
     * @param list<string> $documentation
     *
     * @return array{type: string, value: string, documentation: ?string}
     */
    private function token(string $type, string $value, array $documentation): array
    {
        return [
            'type' => $type,
            'value' => $value,
            'documentation' => [] === $documentation ? null : implode("\n", $documentation),
        ];
    }
}
