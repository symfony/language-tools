<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\DelimiterScanner;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;

final class EventSubscriberMapAnalyzer
{
    private const SUBSCRIBER_INTERFACE = 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface';

    public function __construct(
        private readonly PositionConverter $converter,
    ) {
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(string $uri, string $text, string $source, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($this->subscribedEventMaps($source, $php) as ['offset' => $mapOffset, 'map' => $map]) {
            preg_match_all('/["\']([^"\']+)["\']\s*=>/', $map, $stringEvents, \PREG_OFFSET_CAPTURE);
            foreach ($stringEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($name, $uri, $text, $mapOffset + $offset);
            }
            preg_match_all('/([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*=>/', $map, $classEvents, \PREG_OFFSET_CAPTURE);
            foreach ($classEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($php->resolveName($name), $uri, $text, $mapOffset + $offset, \strlen($name));
            }
        }

        return $symbols;
    }

    public function completionPrefix(string $source, PhpDocument $php, int $offset): ?string
    {
        foreach ($this->subscribedEventMaps($source, $php) as ['offset' => $mapOffset, 'map' => $map]) {
            if ($offset < $mapOffset || $offset > $mapOffset + \strlen($map)) {
                continue;
            }
            if (preg_match('/(?:\[|,)\s*["\']([^"\']*)$/s', substr($map, 0, $offset - $mapOffset), $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * The array returned by every `getSubscribedEvents()` declaration of a class
     * that implements `EventSubscriberInterface`, keyed by its source offset.
     *
     * @return list<array{offset: int, map: string}>
     */
    private function subscribedEventMaps(string $source, PhpDocument $php): array
    {
        $maps = [];
        foreach ($php->methodDeclarations as $method) {
            if ('getSubscribedEvents' !== $method->name
                || !$this->isSubscriber($php, $method)
                || null === $method->bodyStartOffset
            ) {
                continue;
            }
            $body = substr($source, $method->bodyStartOffset, ($method->bodyEndOffset ?? \strlen($source)) - $method->bodyStartOffset);
            if (!preg_match('/\breturn\s*\[/', $body, $return, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = $method->bodyStartOffset + $return[0][1] + \strlen($return[0][0]) - 1;
            $close = DelimiterScanner::close($source, $open) ?? \strlen($source);
            $maps[] = ['offset' => $open + 1, 'map' => substr($source, $open + 1, $close - $open - 1)];
        }

        return $maps;
    }

    private function isSubscriber(PhpDocument $php, PhpMethodDeclaration $method): bool
    {
        foreach ($php->typeDeclarations as $type) {
            if ($method->className === $type->name) {
                return \in_array(self::SUBSCRIBER_INTERFACE, $type->interfaceNames, true);
            }
        }

        return false;
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
}
