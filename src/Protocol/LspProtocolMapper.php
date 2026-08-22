<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;

final class LspProtocolMapper
{
    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    public function range(Range $range): array
    {
        return [
            'start' => $this->position($range->start()),
            'end' => $this->position($range->end()),
        ];
    }

    /** @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} */
    public function location(string $uri, Range $range): array
    {
        return ['uri' => $uri, 'range' => $this->range($range)];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    public function zeroRange(): array
    {
        return [
            'start' => ['line' => 0, 'character' => 0],
            'end' => ['line' => 0, 'character' => 0],
        ];
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    public function diagnostic(Range $range, int $severity, string $code, string $message): array
    {
        return [
            'range' => $this->range($range),
            'severity' => $severity,
            'source' => 'symfony',
            'code' => $code,
            'message' => $message,
        ];
    }

    /** @return array{contents: array{kind: string, value: string}} */
    public function markdownHover(string $value): array
    {
        return ['contents' => ['kind' => 'markdown', 'value' => $value]];
    }

    /** @param array<array-key, mixed> $protocolRange */
    public function sameRange(Range $range, array $protocolRange): bool
    {
        $start = $protocolRange['start'] ?? null;
        $end = $protocolRange['end'] ?? null;

        return \is_array($start)
            && \is_array($end)
            && $range->start()->line() === ($start['line'] ?? null)
            && $range->start()->character() === ($start['character'] ?? null)
            && $range->end()->line() === ($end['line'] ?? null)
            && $range->end()->character() === ($end['character'] ?? null);
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string} */
    public function textEdit(Range $range, string $newText): array
    {
        return ['range' => $this->range($range), 'newText' => $newText];
    }

    /**
     * @param list<array<array-key, mixed>> $locations
     *
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, command: array{title: string, command: string, arguments: array{string, array{line: int, character: int}, list<array<array-key, mixed>>}}}
     */
    public function referenceLens(Range $range, string $title, string $uri, array $locations): array
    {
        return [
            'range' => $this->range($range),
            'command' => [
                'title' => $title,
                'command' => 'editor.action.showReferences',
                'arguments' => [$uri, $this->position($range->start()), $locations],
            ],
        ];
    }

    /** @return array{line: int, character: int} */
    private function position(Position $position): array
    {
        return ['line' => $position->line(), 'character' => $position->character()];
    }
}
