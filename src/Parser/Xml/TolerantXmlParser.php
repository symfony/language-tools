<?php

namespace Symfony\Lsp\Parser\Xml;

final class TolerantXmlParser implements XmlParserInterface
{
    private const MAX_EVENTS = 100_000;
    private const MAX_DIAGNOSTICS = 100;
    private const NAME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:-';

    public function parse(string $source): XmlDocument
    {
        $events = [];
        $diagnostics = [];
        $stack = [];
        $openNameCounts = [];
        $length = \strlen($source);
        $offset = 0;
        $identity = 0;
        $terminalMalformed = false;

        while ($offset < $length) {
            if (\count($events) >= self::MAX_EVENTS) {
                if (\count($diagnostics) >= self::MAX_DIAGNOSTICS) {
                    array_pop($diagnostics);
                }
                $diagnostics[] = new XmlDiagnostic('XML analysis stopped after reaching its structural limit.', $offset, $offset);
                $terminalMalformed = true;
                break;
            }
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
                    $this->appendDiagnostic($diagnostics, $diagnostic);
                    $terminalMalformed = $offset >= $length;
                }
                continue;
            }
            if ($this->startsWith($source, '<![CDATA[', $opening)) {
                [$event, $diagnostic, $offset] = $this->cdata($source, $opening, $this->parentIdentity($stack));
                if (null !== $event) {
                    $events[] = $event;
                }
                if (null !== $diagnostic) {
                    $this->appendDiagnostic($diagnostics, $diagnostic);
                    $terminalMalformed = $offset >= $length;
                }
                continue;
            }
            if ($this->startsWith($source, '<?', $opening)) {
                [$event, $diagnostic, $offset] = $this->terminatedOpaque($source, $opening, '?>', XmlOpaqueKind::ProcessingInstruction, 2, 'XML processing instruction is not closed.', $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $this->appendDiagnostic($diagnostics, $diagnostic);
                    $terminalMalformed = $offset >= $length;
                }
                continue;
            }
            if ($this->startsWith($source, '<!DOCTYPE', $opening)) {
                [$event, $diagnostic, $offset] = $this->doctype($source, $opening, $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $this->appendDiagnostic($diagnostics, $diagnostic);
                    $terminalMalformed = $offset >= $length;
                }
                continue;
            }
            if ($this->startsWith($source, '<!', $opening)) {
                [$event, $diagnostic, $offset] = $this->declaration($source, $opening, $this->parentIdentity($stack));
                $events[] = $event;
                if (null !== $diagnostic) {
                    $this->appendDiagnostic($diagnostics, $diagnostic);
                    $terminalMalformed = $offset >= $length;
                }
                continue;
            }
            if ($this->startsWith($source, '</', $opening)) {
                $closing = $this->closingElement($source, $opening, $stack, $openNameCounts);
                if (null !== $closing) {
                    [$event, $diagnostic, $offset] = $closing;
                    $events[] = $event;
                    if (null !== $diagnostic) {
                        $this->appendDiagnostic($diagnostics, $diagnostic);
                        $terminalMalformed = $offset >= $length;
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
                            $openNameCounts[$event->qualifiedName] = 1 + ($openNameCounts[$event->qualifiedName] ?? 0);
                        }
                    }
                    if (null !== $diagnostic) {
                        $this->appendDiagnostic($diagnostics, $diagnostic);
                        $terminalMalformed = $offset >= $length;
                    }
                    continue;
                }
            }

            $this->appendText($events, $source, $opening, $opening + 1, $this->parentIdentity($stack));
            $offset = $opening + 1;
        }

        if ([] !== $stack && !$terminalMalformed) {
            [, $name] = $stack[array_key_last($stack)];
            $this->appendDiagnostic($diagnostics, new XmlDiagnostic(\sprintf('Element "%s" is not closed.', $name), $length, $length));
        }

        return new XmlDocument($events, $diagnostics);
    }

    /** @param list<XmlDiagnostic> $diagnostics */
    private function appendDiagnostic(array &$diagnostics, XmlDiagnostic $diagnostic): void
    {
        if (\count($diagnostics) < self::MAX_DIAGNOSTICS) {
            $diagnostics[] = $diagnostic;
        }
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
        $limit = \strlen($source);
        $closing = strpos($source, $terminator, $start + $prefixLength);
        if (false !== $closing) {
            $end = $closing + \strlen($terminator);

            return [new XmlOpaque($parentIdentity, $kind, $start, $end, $start + $prefixLength, $closing), null, $end];
        }

        return [
            new XmlOpaque($parentIdentity, $kind, $start, $limit, $start + $prefixLength, $limit),
            new XmlDiagnostic($message, $start, min($limit, $start + $prefixLength)),
            $limit,
        ];
    }

    /** @return array{?XmlText, ?XmlDiagnostic, int} */
    private function cdata(string $source, int $start, ?int $parentIdentity): array
    {
        $limit = \strlen($source);
        $contentStart = $start + 9;
        $closing = strpos($source, ']]>', $contentStart);
        if (false !== $closing) {
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
        $limit = \strlen($source);
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

                return [new XmlOpaque($parentIdentity, XmlOpaqueKind::Doctype, $start, $end, $start + 9, $offset), null, $end];
            }
        }

        return [
            new XmlOpaque($parentIdentity, XmlOpaqueKind::Doctype, $start, $limit, $start + 9, $limit),
            new XmlDiagnostic('XML DOCTYPE is not closed.', $start, min($limit, $start + 9)),
            $limit,
        ];
    }

    /** @return array{XmlOpaque, ?XmlDiagnostic, int} */
    private function declaration(string $source, int $start, ?int $parentIdentity): array
    {
        $limit = \strlen($source);
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

                return [new XmlOpaque($parentIdentity, XmlOpaqueKind::Declaration, $start, $end, $start + 2, $offset), null, $end];
            }
        }

        return [
            new XmlOpaque($parentIdentity, XmlOpaqueKind::Declaration, $start, $limit, $start + 2, $limit),
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
        $limit = \strlen($source);
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
            $valueEnd = strpos($source, $quote, $valueStart);
            $markup = strpos($source, '<', $valueStart);
            while (false !== $markup && $markup < (false === $valueEnd ? $limit : $valueEnd)) {
                if ($this->isRecoveryMarkup($source, $valueStart, $markup)) {
                    return [null, new XmlDiagnostic(\sprintf('Opening element "%s" is not closed.', $qualifiedName), $start + 1, $nameEnd), $markup];
                }
                $markup = strpos($source, '<', $markup + 1);
            }
            if (false === $valueEnd) {
                break;
            }
            $offset = $valueEnd + 1;
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
     * @param array<string, int>       $openNameCounts
     *
     * @return array{XmlElementEnd, ?XmlDiagnostic, int}|null
     */
    private function closingElement(string $source, int $start, array &$stack, array &$openNameCounts): ?array
    {
        $name = $this->name($source, $start + 2);
        if (null === $name) {
            return null;
        }
        [$qualifiedName, $nameEnd] = $name;
        $limit = \strlen($source);
        $offset = $nameEnd;
        $this->skipWhitespace($source, $offset, $limit);
        if ('>' !== ($source[$offset] ?? null)) {
            $next = strpos($source, '<', $offset);
            $end = false === $next || $next > $limit ? $limit : $next;

            return [new XmlElementEnd(null, $qualifiedName, $start, $end, $start + 2, $nameEnd), new XmlDiagnostic(\sprintf('Closing element "%s" is not closed.', $qualifiedName), $start + 2, $nameEnd), $end];
        }
        $end = $offset + 1;
        $top = [] === $stack ? null : $stack[array_key_last($stack)];
        if (null !== $top && $qualifiedName === $top[1]) {
            array_pop($stack);
            $this->removeOpenName($openNameCounts, $qualifiedName);

            return [new XmlElementEnd($top[0], $qualifiedName, $start, $end, $start + 2, $nameEnd), null, $end];
        }

        $diagnostic = new XmlDiagnostic(
            \sprintf('Closing element "%s" does not match "%s".', $qualifiedName, $top[1] ?? 'none'),
            $start + 2,
            $nameEnd,
        );
        if (!isset($openNameCounts[$qualifiedName])) {
            return [new XmlElementEnd(null, $qualifiedName, $start, $end, $start + 2, $nameEnd), $diagnostic, $end];
        }
        do {
            /** @var array{int, string} $entry */
            $entry = array_pop($stack);
            [$identity, $removedName] = $entry;
            $this->removeOpenName($openNameCounts, $removedName);
        } while ($qualifiedName !== $removedName);

        return [new XmlElementEnd($identity, $qualifiedName, $start, $end, $start + 2, $nameEnd), $diagnostic, $end];
    }

    /** @param array<string, int> $openNameCounts */
    private function removeOpenName(array &$openNameCounts, string $name): void
    {
        if (1 === $openNameCounts[$name]) {
            unset($openNameCounts[$name]);
        } else {
            --$openNameCounts[$name];
        }
    }

    /** @return array{string, int}|null */
    private function name(string $source, int $offset): ?array
    {
        $length = \strlen($source);
        if ($offset >= $length || !$this->isNameStart($source[$offset])) {
            return null;
        }
        $start = $offset;
        do {
            $offset += strspn($source, self::NAME_CHARACTERS, $offset);
            while ($offset < $length && \ord($source[$offset]) >= 0x80) {
                ++$offset;
            }
        } while ($offset < $length && (\ord($source[$offset]) >= 0x80 || str_contains(self::NAME_CHARACTERS, $source[$offset])));
        $name = substr($source, $start, $offset - $start);

        return 1 === preg_match('//u', $name) ? [$name, $offset] : null;
    }

    private function isNameStart(string $byte): bool
    {
        $ord = \ord($byte);

        return $ord >= 0x80 || '_' === $byte || ':' === $byte || ($byte >= 'A' && $byte <= 'Z') || ($byte >= 'a' && $byte <= 'z');
    }

    private function skipWhitespace(string $source, int &$offset, int $limit): void
    {
        $offset += strspn($source, " \t\r\n", $offset, $limit - $offset);
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
