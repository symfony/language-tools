<?php

namespace Symfony\Lsp\Parser\Xml;

final class TolerantXmlParser implements XmlParserInterface
{
    private const MAX_CONSTRUCT_BYTES = 65_536;

    public function parse(string $source): XmlDocument
    {
        $events = [];
        $diagnostics = [];
        $stack = [];
        $length = \strlen($source);
        $offset = 0;
        $identity = 0;

        while ($offset < $length) {
            $opening = strpos($source, '<', $offset);
            if (false === $opening) {
                $this->appendText($events, $source, $offset, $length, $this->parentIdentity($stack));
                break;
            }
            $this->appendText($events, $source, $offset, $opening, $this->parentIdentity($stack));

            if ($this->startsWith($source, '<!--', $opening)) {
                [$event, $diagnostic, $offset] = $this->terminatedOpaque($source, $opening, '-->', XmlOpaqueKind::Comment, 4, 'XML comment is not closed.', $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
                continue;
            }
            if ($this->startsWith($source, '<![CDATA[', $opening)) {
                [$event, $diagnostic, $offset] = $this->cdata($source, $opening, $this->parentIdentity($stack));
                if (null !== $event) {
                    $events[] = $event;
                }
                if (null !== $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
                continue;
            }
            if ($this->startsWith($source, '<?', $opening)) {
                [$event, $diagnostic, $offset] = $this->terminatedOpaque($source, $opening, '?>', XmlOpaqueKind::ProcessingInstruction, 2, 'XML processing instruction is not closed.', $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
                continue;
            }
            if ($this->startsWith($source, '<!DOCTYPE', $opening)) {
                [$event, $diagnostic, $offset] = $this->doctype($source, $opening, $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
                continue;
            }
            if ($this->startsWith($source, '<!', $opening)) {
                [$event, $diagnostic, $offset] = $this->declaration($source, $opening, $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $diagnostics[] = $diagnostic;
                }
                continue;
            }
            if ($this->startsWith($source, '</', $opening)) {
                $closing = $this->closingElement($source, $opening, $stack);
                if (null !== $closing) {
                    [$event, $diagnostic, $offset, $stack] = $closing;
                    $events[] = $event;
                    if (null !== $diagnostic) {
                        $diagnostics[] = $diagnostic;
                    }
                    continue;
                }
            } else {
                $start = $this->openingElement($source, $opening, $identity + 1, $this->parentIdentity($stack));
                if (null !== $start) {
                    [$event, $diagnostic, $offset] = $start;
                    if (null !== $event) {
                        ++$identity;
                        $events[] = $event;
                        if (!$event->selfClosing) {
                            $stack[] = [$event->identity, $event->qualifiedName];
                        }
                    }
                    if (null !== $diagnostic) {
                        $diagnostics[] = $diagnostic;
                    }
                    continue;
                }
            }

            $this->appendText($events, $source, $opening, $opening + 1, $this->parentIdentity($stack));
            $offset = $opening + 1;
        }

        while ([] !== $stack) {
            [, $name] = array_pop($stack);
            $diagnostics[] = new XmlDiagnostic(\sprintf('Element "%s" is not closed.', $name), $length, $length);
        }

        return new XmlDocument($events, $diagnostics);
    }

    /**
     * @param list<XmlElementStart|XmlElementEnd|XmlText|XmlOpaque> $events
     */
    private function appendText(array &$events, string $source, int $start, int $end, ?int $parentIdentity, XmlTextKind $kind = XmlTextKind::Text): void
    {
        if ($start >= $end) {
            return;
        }
        $events[] = new XmlText($parentIdentity, substr($source, $start, $end - $start), $start, $end, $kind);
    }

    /**
     * @param list<array{int, string}> $stack
     */
    private function parentIdentity(array $stack): ?int
    {
        return [] === $stack ? null : $stack[array_key_last($stack)][0];
    }

    private function startsWith(string $source, string $needle, int $offset): bool
    {
        return 0 === substr_compare($source, $needle, $offset, \strlen($needle));
    }

    /** @return array{XmlOpaque, ?XmlDiagnostic, int} */
    private function terminatedOpaque(string $source, int $start, string $terminator, XmlOpaqueKind $kind, int $prefixLength, string $message, ?int $parentIdentity): array
    {
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $closing = strpos($source, $terminator, $start + $prefixLength);
        if (false !== $closing && $closing + \strlen($terminator) <= $limit) {
            $end = $closing + \strlen($terminator);

            return [new XmlOpaque($parentIdentity, $kind, substr($source, $start, $end - $start), $start, $end, $start + $prefixLength, $closing), null, $end];
        }

        return [
            new XmlOpaque($parentIdentity, $kind, substr($source, $start, $limit - $start), $start, $limit, $start + $prefixLength, $limit),
            new XmlDiagnostic($message, $start, min($limit, $start + $prefixLength)),
            $limit,
        ];
    }

    /** @return array{?XmlText, ?XmlDiagnostic, int} */
    private function cdata(string $source, int $start, ?int $parentIdentity): array
    {
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $contentStart = $start + 9;
        $closing = strpos($source, ']]>', $contentStart);
        if (false !== $closing && $closing + 3 <= $limit) {
            return [new XmlText($parentIdentity, substr($source, $contentStart, $closing - $contentStart), $contentStart, $closing, XmlTextKind::Cdata), null, $closing + 3];
        }

        return [
            $contentStart < $limit ? new XmlText($parentIdentity, substr($source, $contentStart, $limit - $contentStart), $contentStart, $limit, XmlTextKind::Cdata) : null,
            new XmlDiagnostic('XML CDATA section is not closed.', $start, min($limit, $contentStart)),
            $limit,
        ];
    }

    /** @return array{XmlOpaque, ?XmlDiagnostic, int} */
    private function doctype(string $source, int $start, ?int $parentIdentity): array
    {
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $quote = null;
        $subsetDepth = 0;
        for ($offset = $start + 9; $offset < $limit; ++$offset) {
            $byte = $source[$offset];
            if (null !== $quote) {
                if ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($this->startsWith($source, '<!--', $offset)) {
                $closing = strpos($source, '-->', $offset + 4);
                if (false === $closing || $closing + 3 > $limit) {
                    break;
                }
                $offset = $closing + 2;
                continue;
            }
            if ($this->startsWith($source, '<?', $offset)) {
                $closing = strpos($source, '?>', $offset + 2);
                if (false === $closing || $closing + 2 > $limit) {
                    break;
                }
                $offset = $closing + 1;
                continue;
            }
            if ('"' === $byte || "'" === $byte) {
                $quote = $byte;
            } elseif ('[' === $byte) {
                ++$subsetDepth;
            } elseif (']' === $byte && 0 < $subsetDepth) {
                --$subsetDepth;
            } elseif ('>' === $byte && 0 === $subsetDepth) {
                $end = $offset + 1;

                return [new XmlOpaque($parentIdentity, XmlOpaqueKind::Doctype, substr($source, $start, $end - $start), $start, $end, $start + 9, $offset), null, $end];
            }
        }

        return [
            new XmlOpaque($parentIdentity, XmlOpaqueKind::Doctype, substr($source, $start, $limit - $start), $start, $limit, $start + 9, $limit),
            new XmlDiagnostic('XML DOCTYPE is not closed.', $start, min($limit, $start + 9)),
            $limit,
        ];
    }

    /** @return array{XmlOpaque, ?XmlDiagnostic, int} */
    private function declaration(string $source, int $start, ?int $parentIdentity): array
    {
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $quote = null;
        for ($offset = $start + 2; $offset < $limit; ++$offset) {
            $byte = $source[$offset];
            if (null !== $quote) {
                if ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $byte || "'" === $byte) {
                $quote = $byte;
            } elseif ('>' === $byte) {
                $end = $offset + 1;

                return [new XmlOpaque($parentIdentity, XmlOpaqueKind::Declaration, substr($source, $start, $end - $start), $start, $end, $start + 2, $offset), null, $end];
            }
        }

        return [
            new XmlOpaque($parentIdentity, XmlOpaqueKind::Declaration, substr($source, $start, $limit - $start), $start, $limit, $start + 2, $limit),
            new XmlDiagnostic('XML declaration is not closed.', $start, min($limit, $start + 2)),
            $limit,
        ];
    }

    /** @return array{?XmlElementStart, ?XmlDiagnostic, int}|null */
    private function openingElement(string $source, int $start, int $identity, ?int $parentIdentity): ?array
    {
        $name = $this->name($source, $start + 1);
        if (null === $name) {
            return null;
        }
        [$qualifiedName, $nameEnd] = $name;
        $attributes = [];
        $diagnostic = null;
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $offset = $nameEnd;

        while ($offset < $limit) {
            $this->skipWhitespace($source, $offset, $limit);
            if ($offset >= $limit) {
                break;
            }
            if ('>' === $source[$offset]) {
                return [new XmlElementStart($identity, $parentIdentity, $qualifiedName, $start, $offset + 1, $start + 1, $nameEnd, $attributes, false), $diagnostic, $offset + 1];
            }
            if ('/' === $source[$offset] && '>' === ($source[$offset + 1] ?? null)) {
                return [new XmlElementStart($identity, $parentIdentity, $qualifiedName, $start, $offset + 2, $start + 1, $nameEnd, $attributes, true), $diagnostic, $offset + 2];
            }
            if ('<' === $source[$offset]) {
                return [null, new XmlDiagnostic(\sprintf('Opening element "%s" is not closed.', $qualifiedName), $start + 1, $nameEnd), $offset];
            }

            $attributeStart = $offset;
            $attributeName = $this->name($source, $offset);
            if (null === $attributeName) {
                $diagnostic ??= new XmlDiagnostic(\sprintf('Opening element "%s" is malformed.', $qualifiedName), $offset, min($limit, $offset + 1));
                ++$offset;
                continue;
            }
            [$attributeQualifiedName, $attributeNameEnd] = $attributeName;
            $offset = $attributeNameEnd;
            $this->skipWhitespace($source, $offset, $limit);
            if ('=' !== ($source[$offset] ?? null)) {
                $diagnostic ??= new XmlDiagnostic(\sprintf('Attribute "%s" has no value.', $attributeQualifiedName), $attributeStart, $attributeNameEnd);
                continue;
            }
            ++$offset;
            $this->skipWhitespace($source, $offset, $limit);
            $quote = $source[$offset] ?? null;
            if (!\in_array($quote, ['"', "'"], true)) {
                $diagnostic ??= new XmlDiagnostic(\sprintf('Attribute "%s" is not quoted.', $attributeQualifiedName), $attributeStart, min($limit, $offset + 1));
                while ($offset < $limit && !$this->isWhitespace($source[$offset]) && !\in_array($source[$offset], ['>', '<'], true)) {
                    ++$offset;
                }
                continue;
            }
            $valueStart = ++$offset;
            while ($offset < $limit && $source[$offset] !== $quote) {
                if ('<' === $source[$offset] && $this->isRecoveryMarkup($source, $valueStart, $offset)) {
                    return [null, new XmlDiagnostic(\sprintf('Opening element "%s" is not closed.', $qualifiedName), $start + 1, $nameEnd), $offset];
                }
                ++$offset;
            }
            if ($offset >= $limit) {
                break;
            }
            $valueEnd = $offset;
            ++$offset;
            $attributes[] = new XmlAttribute(
                $attributeQualifiedName,
                substr($source, $valueStart, $valueEnd - $valueStart),
                $attributeStart,
                $offset,
                $attributeStart,
                $attributeNameEnd,
                $valueStart,
                $valueEnd,
                $quote,
            );
        }

        return [null, new XmlDiagnostic(\sprintf('Opening element "%s" is not closed.', $qualifiedName), $start + 1, $nameEnd), $limit];
    }

    /**
     * @param list<array{int, string}> $stack
     *
     * @return array{XmlElementEnd, ?XmlDiagnostic, int, list<array{int, string}>}|null
     */
    private function closingElement(string $source, int $start, array $stack): ?array
    {
        $name = $this->name($source, $start + 2);
        if (null === $name) {
            return null;
        }
        [$qualifiedName, $nameEnd] = $name;
        $length = \strlen($source);
        $limit = min($length, $start + self::MAX_CONSTRUCT_BYTES);
        $offset = $nameEnd;
        $this->skipWhitespace($source, $offset, $limit);
        if ('>' !== ($source[$offset] ?? null)) {
            $next = strpos($source, '<', $offset);
            $end = false === $next || $next > $limit ? $limit : $next;

            return [new XmlElementEnd(null, $qualifiedName, $start, $end, $start + 2, $nameEnd), new XmlDiagnostic(\sprintf('Closing element "%s" is not closed.', $qualifiedName), $start + 2, $nameEnd), $end, $stack];
        }
        $end = $offset + 1;
        $top = [] === $stack ? null : $stack[array_key_last($stack)];
        if (null !== $top && $qualifiedName === $top[1]) {
            array_pop($stack);

            return [new XmlElementEnd($top[0], $qualifiedName, $start, $end, $start + 2, $nameEnd), null, $end, $stack];
        }

        $diagnostic = new XmlDiagnostic(
            \sprintf('Closing element "%s" does not match "%s".', $qualifiedName, $top[1] ?? 'none'),
            $start + 2,
            $nameEnd,
        );
        $match = null;
        for ($index = \count($stack) - 1; 0 <= $index; --$index) {
            if ($qualifiedName === $stack[$index][1]) {
                $match = $index;
                break;
            }
        }
        if (null === $match) {
            return [new XmlElementEnd(null, $qualifiedName, $start, $end, $start + 2, $nameEnd), $diagnostic, $end, $stack];
        }
        $identity = $stack[$match][0];
        $stack = \array_slice($stack, 0, $match);

        return [new XmlElementEnd($identity, $qualifiedName, $start, $end, $start + 2, $nameEnd), $diagnostic, $end, $stack];
    }

    /** @return array{string, int}|null */
    private function name(string $source, int $offset): ?array
    {
        $length = \strlen($source);
        if ($offset >= $length || !$this->isNameStart($source[$offset])) {
            return null;
        }
        $start = $offset++;
        while ($offset < $length && $this->isNameCharacter($source[$offset])) {
            ++$offset;
        }

        return [substr($source, $start, $offset - $start), $offset];
    }

    private function isNameStart(string $byte): bool
    {
        $ord = \ord($byte);

        return $ord >= 0x80 || '_' === $byte || ':' === $byte || ($byte >= 'A' && $byte <= 'Z') || ($byte >= 'a' && $byte <= 'z');
    }

    private function isNameCharacter(string $byte): bool
    {
        return $this->isNameStart($byte) || ($byte >= '0' && $byte <= '9') || '.' === $byte || '-' === $byte;
    }

    private function skipWhitespace(string $source, int &$offset, int $limit): void
    {
        while ($offset < $limit && $this->isWhitespace($source[$offset])) {
            ++$offset;
        }
    }

    private function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private function isRecoveryMarkup(string $source, int $valueStart, int $offset): bool
    {
        $before = $offset - 1;
        while ($before >= $valueStart && \in_array($source[$before], [' ', "\t"], true)) {
            --$before;
        }
        if ($before < $valueStart || !\in_array($source[$before], ["\r", "\n"], true)) {
            return false;
        }
        $next = $source[$offset + 1] ?? '';
        if ('/' === $next) {
            $next = $source[$offset + 2] ?? '';
        }

        return '' !== $next && ($this->isNameStart($next) || '!' === $next || '?' === $next);
    }
}
