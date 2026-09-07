<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;
use Symfony\Lsp\Index\SourceFactsInterface;

/**
 * Censuses the source facts of a project to suggest scenario candidates.
 *
 * The explorer only counts facts and keeps a bounded number of representative
 * positions per provider and fact class, so candidates say where a feature
 * could be exercised, never that the server answers correctly there. Payload
 * values are never read: only class names, enum kinds, file paths and ranges
 * leave the object graph.
 *
 * @phpstan-type CandidatePosition array{file: string, line: int, character: int, scenario: string|null}
 * @phpstan-type CandidateGroup array{provider: string, fact: string, kind: string|null, count: int, withPosition: int, distinctPositions: int, files: int, withScenario: int, scenarios: list<string>, positions: list<CandidatePosition>}
 * @phpstan-type Census array{files: int, facts: int, depthLimitReached: bool, groups: list<CandidateGroup>, scenarios: array{covered: list<string>, uncovered: list<string>}|null}
 */
final class SourceFactExplorer
{
    /** @var array<string, array{provider: string, fact: string, kind: string|null, count: int, withPosition: int, withScenario: int, files: array<string, true>, positionKeys: array<string, true>, scenarios: array<string, true>, positions: list<CandidatePosition>}> */
    private array $groups = [];

    /** @var array<string, true> */
    private array $files = [];

    /** @var array<class-string, array{kind: string|null, range: string|null}> */
    private array $shapes = [];

    private int $facts = 0;

    private bool $depthLimitReached = false;

    public function __construct(
        private readonly int $positionsPerGroup = 3,
        private readonly ?ScenarioPositionIndex $scenarios = null,
        private readonly int $maximumDepth = 16,
    ) {
        if ($positionsPerGroup < 0) {
            throw new \InvalidArgumentException('The number of positions per group cannot be negative.');
        }
    }

    /**
     * Records every fact of one provider payload of one indexed file.
     *
     * @param string $file the project-relative path of the indexed file
     */
    public function add(string $provider, string $file, SourceFactsInterface $facts): void
    {
        $this->files[$file] = true;
        /** @var \SplObjectStorage<object, null> $visited */
        $visited = new \SplObjectStorage();
        $visited->offsetSet($facts, null);
        foreach ((array) $facts as $value) {
            $this->walk($provider, $file, $value, $visited, 1);
        }
    }

    /** @return Census */
    public function census(): array
    {
        $groups = [];
        foreach ($this->groups as $group) {
            $groups[] = [
                'provider' => $group['provider'],
                'fact' => $group['fact'],
                'kind' => $group['kind'],
                'count' => $group['count'],
                'withPosition' => $group['withPosition'],
                'distinctPositions' => \count($group['positionKeys']),
                'files' => \count($group['files']),
                'withScenario' => $group['withScenario'],
                'scenarios' => $this->sorted($group['scenarios']),
                'positions' => $group['positions'],
            ];
        }
        usort($groups, static fn (array $left, array $right): int => [$left['provider'], $left['fact'], $left['kind'] ?? ''] <=> [$right['provider'], $right['fact'], $right['kind'] ?? '']);

        return [
            'files' => \count($this->files),
            'facts' => $this->facts,
            'depthLimitReached' => $this->depthLimitReached,
            'groups' => $groups,
            'scenarios' => null === $this->scenarios ? null : $this->scenarioCoverage(),
        ];
    }

    /** @param \SplObjectStorage<object, null> $visited */
    private function walk(string $provider, string $file, mixed $value, \SplObjectStorage $visited, int $depth): void
    {
        if (\is_array($value)) {
            if ($depth > $this->maximumDepth) {
                $this->depthLimitReached = true;

                return;
            }
            foreach ($value as $item) {
                $this->walk($provider, $file, $item, $visited, $depth + 1);
            }

            return;
        }
        // Scalars carry the indexed values and never leave the object graph.
        if (!\is_object($value) || $visited->offsetExists($value)) {
            return;
        }
        $visited->offsetSet($value, null);
        // Kinds and coordinates are read from their owner, never explored.
        if ($value instanceof \UnitEnum || $value instanceof Position || $value instanceof Range) {
            return;
        }
        if ($depth > $this->maximumDepth) {
            $this->depthLimitReached = true;

            return;
        }

        $this->record($provider, $file, $value);
        foreach ((array) $value as $item) {
            $this->walk($provider, $file, $item, $visited, $depth + 1);
        }
    }

    private function record(string $provider, string $file, object $value): void
    {
        ++$this->facts;
        $shape = $this->shape($value);
        $kind = null === $shape['kind'] ? null : $this->kind($value, $shape['kind']);
        $key = $provider."\0".$value::class."\0".($kind ?? '');
        $group = $this->groups[$key] ??= [
            'provider' => $provider,
            'fact' => $value::class,
            'kind' => $kind,
            'count' => 0,
            'withPosition' => 0,
            'withScenario' => 0,
            'files' => [],
            'positionKeys' => [],
            'scenarios' => [],
            'positions' => [],
        ];
        ++$group['count'];
        $group['files'][$file] = true;
        $range = $this->range($value, $shape['range']);
        if (null !== $range) {
            ++$group['withPosition'];
            $group['positionKeys'][$file."\0".$range->start->line."\0".$range->start->character] = true;
            $scenario = $this->scenarios?->match($file, $range);
            if (null !== $scenario) {
                ++$group['withScenario'];
                $group['scenarios'][$scenario] = true;
            }
            $group['positions'] = $this->sample($group['positions'], $file, $range->start, $scenario);
        }
        $this->groups[$key] = $group;
    }

    /**
     * Keeps the first positions in project order, preferring candidates that no
     * scenario covers yet, independently of the order files are indexed in.
     *
     * @param list<CandidatePosition> $positions
     *
     * @return list<CandidatePosition>
     */
    private function sample(array $positions, string $file, Position $start, ?string $scenario): array
    {
        if (0 === $this->positionsPerGroup) {
            return $positions;
        }
        foreach ($positions as $position) {
            if ($position['file'] === $file && $position['line'] === $start->line && $position['character'] === $start->character) {
                return $positions;
            }
        }
        $positions[] = ['file' => $file, 'line' => $start->line, 'character' => $start->character, 'scenario' => $scenario];
        usort($positions, static fn (array $left, array $right): int => [null === $left['scenario'] ? 0 : 1, $left['file'], $left['line'], $left['character']] <=> [null === $right['scenario'] ? 0 : 1, $right['file'], $right['line'], $right['character']]);

        return \array_slice($positions, 0, $this->positionsPerGroup);
    }

    /** @return array{covered: list<string>, uncovered: list<string>} */
    private function scenarioCoverage(): array
    {
        $covered = [];
        foreach ($this->groups as $group) {
            $covered += $group['scenarios'];
        }
        $uncovered = [];
        foreach ($this->scenarios?->ids() ?? [] as $id) {
            if (!isset($covered[$id])) {
                $uncovered[$id] = true;
            }
        }

        return ['covered' => $this->sorted($covered), 'uncovered' => $this->sorted($uncovered)];
    }

    private function range(object $value, ?string $property): ?Range
    {
        if ($value instanceof RangedSourceSymbolInterface) {
            return $value->range;
        }
        if (null === $property) {
            return null;
        }
        $range = new \ReflectionProperty($value, $property)->getValue($value);

        return $range instanceof Range ? $range : null;
    }

    private function kind(object $value, string $property): ?string
    {
        $kind = new \ReflectionProperty($value, $property)->getValue($value);

        return $kind instanceof \UnitEnum ? $kind->name : null;
    }

    /**
     * @return array{kind: string|null, range: string|null} the properties holding the kind and the first declared range
     */
    private function shape(object $value): array
    {
        if (isset($this->shapes[$value::class])) {
            return $this->shapes[$value::class];
        }
        $shape = ['kind' => null, 'range' => null];
        foreach (new \ReflectionObject($value)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->isVirtual()) {
                continue;
            }
            $type = $property->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if (null === $shape['range'] && Range::class === $name) {
                $shape['range'] = $property->getName();
            }
            if (null === $shape['kind'] && 'kind' === $property->getName() && null !== $name && enum_exists($name)) {
                $shape['kind'] = $property->getName();
            }
        }

        return $this->shapes[$value::class] = $shape;
    }

    /**
     * @param array<string, true> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $sorted = array_keys($values);
        sort($sorted, \SORT_STRING);

        return $sorted;
    }
}
