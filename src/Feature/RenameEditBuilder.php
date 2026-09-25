<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RenameEditBuilder
{
    public function __construct(private readonly LspProtocolMapper $protocol)
    {
    }

    /**
     * @param iterable<array{string, Range, string}> $locations URI, range and replacement text of every edit, the first one winning when a range repeats
     *
     * @return list<array{textDocument: array{uri: string, version: null}, edits: list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>}>
     */
    public function documentChanges(iterable $locations, string $annotationId): array
    {
        /** @var array<string, array<string, array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>> $editsByUri */
        $editsByUri = [];
        foreach ($locations as [$uri, $range, $newText]) {
            $key = \sprintf('%d:%d:%d:%d', $range->start->line, $range->start->character, $range->end->line, $range->end->character);
            $editsByUri[$uri][$key] ??= [
                'range' => $this->protocol->range($range),
                'newText' => $newText,
                'annotationId' => $annotationId,
            ];
        }
        ksort($editsByUri);

        $documentChanges = [];
        foreach ($editsByUri as $uri => $edits) {
            $documentChanges[] = [
                'textDocument' => ['uri' => $uri, 'version' => null],
                'edits' => array_values($edits),
            ];
        }

        return $documentChanges;
    }
}
