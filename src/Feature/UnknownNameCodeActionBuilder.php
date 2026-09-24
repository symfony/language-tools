<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class UnknownNameCodeActionBuilder
{
    public function __construct(private readonly LspProtocolMapper $protocol)
    {
    }

    /**
     * @param array<array-key, mixed> $diagnostic
     * @param iterable<string>        $candidates
     * @param list<Range>             $additionalRanges
     *
     * @return list<array<array-key, mixed>>
     */
    public function replacements(Document $document, array $diagnostic, Range $range, string $name, iterable $candidates, array $additionalRanges = []): array
    {
        if ('' === $name || \strlen($name) > 255) {
            return [];
        }

        $threshold = \strlen($name) < 8 ? 1 : 2;
        $matches = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if ($candidate === $name || isset($seen[$candidate]) || abs(\strlen($candidate) - \strlen($name)) > $threshold) {
                continue;
            }
            $distance = levenshtein(strtolower($name), strtolower($candidate));
            if ($distance <= $threshold) {
                $matches[] = ['name' => $candidate, 'distance' => $distance];
                $seen[$candidate] = true;
            }
        }
        usort($matches, static fn (array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: strcmp($a['name'], $b['name']));
        $preferred = isset($matches[0]) && $matches[0]['distance'] <= 1 && (!isset($matches[1]) || $matches[1]['distance'] > $matches[0]['distance']);

        $actions = [];
        foreach (\array_slice($matches, 0, 3) as $match) {
            $candidate = $match['name'];
            $edits = [$this->protocol->textEdit($range, $candidate)];
            foreach ($additionalRanges as $additionalRange) {
                $edits[] = $this->protocol->textEdit($additionalRange, $candidate);
            }
            $actions[] = [
                'title' => \sprintf('Replace with "%s"', $candidate),
                'kind' => 'quickfix',
                'diagnostics' => [$diagnostic],
                'isPreferred' => $preferred && [] === $actions,
                'edit' => ['documentChanges' => [[
                    'textDocument' => ['uri' => $document->uri, 'version' => $document->version],
                    'edits' => $edits,
                ]]],
            ];
        }

        return $actions;
    }
}
