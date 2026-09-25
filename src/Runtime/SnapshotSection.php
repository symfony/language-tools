<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

/**
 * Reads one runtime snapshot section, whose payload is decoded JSON that no
 * schema validates: every getter answers with the requested type, an absent
 * key is worth an empty value, and a path is mapped back to the host.
 */
final class SnapshotSection
{
    /** @param array<array-key, mixed> $values */
    public function __construct(
        private readonly Project $project,
        private readonly ContainerPathMapper $pathMapper,
        private readonly array $values = [],
    ) {
    }

    /**
     * Completeness of the whole section, or of one of the sets a section that
     * exports several of them reports separately.
     */
    public function complete(string $set = ''): bool
    {
        return $this->bool('' === $set ? 'complete' : $set.'Complete');
    }

    /** A section reports the subsystem it describes as absent with false. */
    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function string(string $key): string
    {
        return $this->optionalString($key) ?? '';
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return $this->optionalBool($key) ?? $default;
    }

    public function optionalBool(string $key): ?bool
    {
        $value = $this->values[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    public function path(string $key): string
    {
        return $this->pathMapper->toHost($this->project, $this->string($key));
    }

    public function optionalPath(string $key): ?string
    {
        $value = $this->optionalString($key);

        return null === $value ? null : $this->pathMapper->toHost($this->project, $value);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $values = $this->values[$key] ?? null;

        return \is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /** @return array<string, string> */
    public function stringMap(string $key): array
    {
        $map = [];
        foreach ($this->map($key) as $name => $value) {
            if (\is_string($value)) {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /** @return array<string, bool> */
    public function boolMap(string $key): array
    {
        $map = [];
        foreach ($this->map($key) as $name => $value) {
            if (\is_bool($value)) {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /** @return list<string|int|float|bool|null> */
    public function scalars(string $key): array
    {
        $values = $this->values[$key] ?? null;
        $scalars = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (null === $value || \is_scalar($value)) {
                $scalars[] = $value;
            }
        }

        return $scalars;
    }

    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** A nested section, such as the configuration tree of one bundle. */
    public function section(string $key): ?self
    {
        $values = $this->values[$key] ?? null;

        return \is_array($values) ? new self($this->project, $this->pathMapper, $values) : null;
    }

    /**
     * The items of a list, skipping the ones that are not readable as an item
     * of the requested shape.
     *
     * @return list<self>
     */
    public function items(string $key, string ...$requiredStringKeys): array
    {
        $values = $this->values[$key] ?? null;
        $items = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (!\is_array($value)) {
                continue;
            }
            $item = new self($this->project, $this->pathMapper, $value);
            foreach ($requiredStringKeys as $requiredKey) {
                if (null === $item->optionalString($requiredKey)) {
                    continue 2;
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function map(string $key): array
    {
        $values = $this->values[$key] ?? null;
        $map = [];
        foreach (\is_array($values) ? $values : [] as $name => $value) {
            if (\is_string($name)) {
                $map[$name] = $value;
            }
        }

        return $map;
    }
}
