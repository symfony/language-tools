<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;

/**
 * Tells which reviewed scenario, if any, already targets a candidate range.
 *
 * Positions are the UTF-16 coordinates a scenario resolves to in the checked
 * out sources, so a candidate counts as covered when the scenario cursor falls
 * inside its range, the same containment the server resolves symbols with.
 */
final class ScenarioPositionIndex
{
    /** @var array<string, list<array{id: string, position: Position}>> */
    private array $byFile = [];

    /** @var list<string> */
    private array $ids;

    /** @param list<array{id: string, file: string, position: Position}> $scenarios */
    public function __construct(array $scenarios)
    {
        $ids = [];
        foreach ($scenarios as $scenario) {
            $this->byFile[$scenario['file']][] = ['id' => $scenario['id'], 'position' => $scenario['position']];
            $ids[$scenario['id']] = true;
        }
        foreach ($this->byFile as &$entries) {
            usort($entries, static fn (array $left, array $right): int => [$left['position']->line, $left['position']->character, $left['id']] <=> [$right['position']->line, $right['position']->character, $right['id']]);
        }
        unset($entries);
        $this->ids = array_keys($ids);
        sort($this->ids, \SORT_STRING);
    }

    public function match(string $file, Range $range): ?string
    {
        foreach ($this->byFile[$file] ?? [] as $entry) {
            $position = $entry['position'];
            if ([$range->start->line, $range->start->character] <= [$position->line, $position->character]
                && [$position->line, $position->character] <= [$range->end->line, $range->end->character]
            ) {
                return $entry['id'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->ids;
    }
}
