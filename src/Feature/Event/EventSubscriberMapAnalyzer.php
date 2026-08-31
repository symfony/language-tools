<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpDocument;

final class EventSubscriberMapAnalyzer
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(string $uri, string $text, string $source, PhpDocument $php): array
    {
        $symbols = [];
        preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $source, $subscriberMethods, \PREG_OFFSET_CAPTURE);
        foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
            $open = $declarationOffset + \strlen($declaration) - 1;
            $close = $this->matchingBrace($source, $open);
            $body = substr($source, $open + 1, $close - $open - 1);
            preg_match_all('/["\']([^"\']+)["\']\s*=>/', $body, $stringEvents, \PREG_OFFSET_CAPTURE);
            foreach ($stringEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($name, $uri, $text, $open + 1 + $offset);
            }
            preg_match_all('/([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*=>/', $body, $classEvents, \PREG_OFFSET_CAPTURE);
            foreach ($classEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($php->resolveName($name), $uri, $text, $open + 1 + $offset, \strlen($name));
            }
        }

        return $symbols;
    }

    public function completionPrefix(string $source, int $offset): ?string
    {
        preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $source, $subscriberMethods, \PREG_OFFSET_CAPTURE);
        foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
            $open = $declarationOffset + \strlen($declaration) - 1;
            if ($offset <= $open || $offset > $this->matchingBrace($source, $open)) {
                continue;
            }
            $bodyBefore = substr($source, $open + 1, $offset - $open - 1);
            if (preg_match('/(?:\[|,)\s*["\']([^"\']*)$/s', $bodyBefore, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    private function symbol(string $name, string $uri, string $text, int $offset, ?int $length = null): EventSourceSymbol
    {
        return new EventSourceSymbol(
            ltrim($name, '\\'),
            $uri,
            new Range(
                $this->converter->toPosition($text, $offset),
                $this->converter->toPosition($text, $offset + ($length ?? \strlen($name))),
            ),
            true,
        );
    }

    private function matchingBrace(string $text, int $open): int
    {
        $depth = 0;
        for ($offset = $open, $length = \strlen($text); $offset < $length; ++$offset) {
            if ('{' === $text[$offset]) {
                ++$depth;
            } elseif ('}' === $text[$offset] && 0 === --$depth) {
                return $offset;
            }
        }

        return \strlen($text);
    }
}
