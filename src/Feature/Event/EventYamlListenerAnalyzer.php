<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class EventYamlListenerAnalyzer
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(string $uri, string $text): array
    {
        [$events] = $this->analyze($text);

        return array_map(fn (array $event): EventSourceSymbol => new EventSourceSymbol(
            ltrim($event['name'], '\\'),
            $uri,
            new Range(
                $this->converter->toPosition($text, $event['offset']),
                $this->converter->toPosition($text, $event['offset'] + \strlen($event['name'])),
            ),
            true,
        ), $events);
    }

    public function completionPrefix(string $text): ?string
    {
        [, $prefix] = $this->analyze($text);

        return $prefix;
    }

    /** @return array{list<array{name: string, offset: int}>, string|null} */
    private function analyze(string $text): array
    {
        $events = [];
        $completionPrefix = null;
        $listenerIndent = null;
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        $lastLine = array_key_last($lines[0]);
        foreach ($lines[0] as $index => [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            if (preg_match('/^(\s*)-\s*(?:\{\s*)?name\s*:\s*["\']?kernel\.event_listener["\']?(.*)$/', $line, $tag)) {
                $listenerIndent = \strlen($tag[1]);
                if (preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $tag[2], $event, \PREG_OFFSET_CAPTURE)) {
                    $events[] = [
                        'name' => $event[1][0],
                        'offset' => $lineOffset + (int) strpos($line, $tag[2]) + $event[1][1],
                    ];
                }
                if ($index === $lastLine && preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $tag[2], $event)) {
                    $completionPrefix = $event[1];
                }
                continue;
            }
            if (null === $listenerIndent || !preg_match('/^(\s*)/', $line, $indentMatch)) {
                continue;
            }
            $indent = \strlen($indentMatch[1]);
            if ('' !== trim($line) && $indent <= $listenerIndent) {
                $listenerIndent = null;
                continue;
            }
            if (preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $line, $event, \PREG_OFFSET_CAPTURE)) {
                $events[] = ['name' => $event[1][0], 'offset' => $lineOffset + $event[1][1]];
            }
            if ($index === $lastLine && preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $line, $event)) {
                $completionPrefix = $event[1];
            }
        }

        return [$events, $completionPrefix];
    }
}
