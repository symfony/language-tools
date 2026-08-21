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

    /** @return array{line: int, character: int} */
    private function position(Position $position): array
    {
        return ['line' => $position->line(), 'character' => $position->character()];
    }
}
