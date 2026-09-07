<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Loads the reviewed scenario manifest of a dogfood project.
 *
 * Every scenario must select a source anchor and assert at least one positive
 * or explicitly empty result, so a manifest can never silently skip coverage.
 *
 * @phpstan-type DiagnosticEntry array{path: string, code: string, severity: int, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}
 */
final class ScenarioManifestLoader
{
    private const VERSION = 1;
    private const KEYS = ['version', 'revision', 'scenarios', 'diagnostics'];
    private const SCENARIO_KEYS = ['id', 'file', 'anchor', 'offset', 'expect', 'newName', 'edit'];
    private const EDIT_KEYS = ['before', 'after', 'file', 'anchor', 'offset', 'expect', 'applyCodeAction', 'afterFix'];
    private const DIAGNOSTIC_KEYS = ['path', 'code', 'severity', 'range'];
    private const FEATURES = ['completion', 'hover', 'definition', 'references', 'documentLink', 'codeLens', 'prepareRename', 'rename', 'codeAction', 'diagnostics'];
    private const EXPECTATION_KEYS = ['equals', 'includes', 'excludes'];
    private const MAX_EDIT_LENGTH = 2000;
    private const MAX_SEVERITY = 4;

    /**
     * @param string|null $revision the revision the project is pinned to, when the manifest must be bound to it
     */
    public function load(string $path, ?string $revision = null): ScenarioManifest
    {
        $data = $this->decode($path);
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in "%s".', $key, $path));
            }
        }
        if (self::VERSION !== ($data['version'] ?? null)) {
            throw new ConfigurationException(\sprintf('The manifest in "%s" must declare "version": %d.', $path, self::VERSION));
        }
        $manifestRevision = $data['revision'] ?? null;
        if (!\is_string($manifestRevision) || 1 !== preg_match('/^[0-9a-f]{40}$/', $manifestRevision)) {
            throw new ConfigurationException(\sprintf('The "revision" in "%s" must be a full lowercase commit hash.', $path));
        }
        if (null !== $revision && $revision !== $manifestRevision) {
            throw new ConfigurationException(\sprintf('The manifest in "%s" is reviewed for revision "%s" but the project is pinned to "%s".', $path, $manifestRevision, $revision));
        }

        return new ScenarioManifest($manifestRevision, $this->scenarios($data, $path), $this->diagnostics($data, $path));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $path): array
    {
        if (!is_file($path)) {
            throw new ConfigurationException(\sprintf('The scenario manifest "%s" does not exist.', $path));
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new ConfigurationException(\sprintf('Unable to read "%s".', $path));
        }
        try {
            $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException(\sprintf('Invalid JSON in "%s": %s.', $path, $e->getMessage()));
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw new ConfigurationException(\sprintf('The manifest in "%s" must be an object.', $path));
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function scenarios(array $data, string $path): array
    {
        $scenarios = $data['scenarios'] ?? null;
        if (!\is_array($scenarios) || [] === $scenarios || !array_is_list($scenarios)) {
            throw new ConfigurationException(\sprintf('The "scenarios" in "%s" must be a non-empty list of scenarios.', $path));
        }
        $loaded = [];
        $ids = [];
        foreach ($scenarios as $index => $scenario) {
            $loaded[] = $scenario = $this->scenario($scenario, $index, $path);
            $id = $scenario['id'];
            if (isset($ids[$id])) {
                throw new ConfigurationException(\sprintf('Duplicate scenario "%s" in "%s".', $id, $path));
            }
            $ids[$id] = true;
        }

        return $loaded;
    }

    /**
     * @return array{id: string, file: string, anchor: string, offset: int, expect: array<string, array<string, list<string>>>, newName?: string, edit?: array<string, mixed>}
     */
    private function scenario(mixed $scenario, int $index, string $path): array
    {
        $context = \sprintf('scenario #%d of "%s"', $index, $path);
        if (!\is_array($scenario) || [] === $scenario || array_is_list($scenario)) {
            throw new ConfigurationException(\sprintf('The %s must be an object.', $context));
        }
        $id = $scenario['id'] ?? null;
        if (!\is_string($id) || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]{2,}$/', $id)) {
            throw new ConfigurationException(\sprintf('The "id" in %s must be a lowercase name of at least three characters.', $context));
        }
        $context = \sprintf('scenario "%s" of "%s"', $id, $path);
        foreach (array_keys($scenario) as $key) {
            if (!\in_array($key, self::SCENARIO_KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in %s.', $key, $context));
            }
        }
        $anchor = $this->anchor($scenario['anchor'] ?? null, $context);
        $loaded = [
            'id' => $id,
            'file' => $this->relativePath($scenario['file'] ?? null, 'file', $context),
            'anchor' => $anchor,
            'offset' => $this->offset($scenario['offset'] ?? 0, $anchor, $context),
            'expect' => $this->expectations($scenario['expect'] ?? null, 'expect', $context),
        ];
        $newName = $scenario['newName'] ?? null;
        if (isset($loaded['expect']['rename']) !== (null !== $newName)) {
            throw new ConfigurationException(\sprintf('The %s must declare "newName" if and only if it expects "rename" results.', $context));
        }
        if (null !== $newName) {
            if (!\is_string($newName) || '' === $newName || str_contains($newName, "\0")) {
                throw new ConfigurationException(\sprintf('The "newName" in %s must be a non-empty string.', $context));
            }
            $loaded['newName'] = $newName;
        }
        if (\array_key_exists('edit', $scenario)) {
            $loaded['edit'] = $this->edit($scenario['edit'], $loaded['file'], $context);
        }

        return $loaded;
    }

    /**
     * @return array<string, mixed>
     */
    private function edit(mixed $edit, string $file, string $context): array
    {
        $context = 'the edit of '.$context;
        if (!\is_array($edit) || [] === $edit || array_is_list($edit)) {
            throw new ConfigurationException(\sprintf('The %s must be an object.', ucfirst($context)));
        }
        foreach (array_keys($edit) as $key) {
            if (!\in_array($key, self::EDIT_KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in %s.', $key, $context));
            }
        }
        $before = $edit['before'] ?? null;
        $after = $edit['after'] ?? null;
        if (!\is_string($before) || '' === $before || !$this->isSafeText($before) || \strlen($before) > self::MAX_EDIT_LENGTH) {
            throw new ConfigurationException(\sprintf('The "before" in %s must be a non-empty UTF-8 string of at most %d bytes.', $context, self::MAX_EDIT_LENGTH));
        }
        if (!\is_string($after) || !$this->isSafeText($after) || \strlen($after) > self::MAX_EDIT_LENGTH) {
            throw new ConfigurationException(\sprintf('The "after" in %s must be a UTF-8 string of at most %d bytes.', $context, self::MAX_EDIT_LENGTH));
        }
        if ($before === $after) {
            throw new ConfigurationException(\sprintf('The "before" and "after" in %s must differ.', $context));
        }
        $loaded = [
            'before' => $before,
            'after' => $after,
            'file' => \array_key_exists('file', $edit) ? $this->relativePath($edit['file'], 'file', $context) : $file,
            'expect' => $this->expectations($edit['expect'] ?? null, 'expect', $context),
        ];
        if (\array_key_exists('anchor', $edit)) {
            $loaded['anchor'] = $anchor = $this->anchor($edit['anchor'], $context);
            $loaded['offset'] = $this->offset($edit['offset'] ?? 0, $anchor, $context);
        } elseif (\array_key_exists('offset', $edit)) {
            throw new ConfigurationException(\sprintf('The "offset" in %s requires an "anchor".', $context));
        }
        $applyCodeAction = $edit['applyCodeAction'] ?? null;
        if ((null !== $applyCodeAction) !== \array_key_exists('afterFix', $edit)) {
            throw new ConfigurationException(\sprintf('The %s must declare "afterFix" if and only if it declares "applyCodeAction".', $context));
        }
        if (null !== $applyCodeAction) {
            if (!\is_string($applyCodeAction) || '' === $applyCodeAction || !$this->isSafeText($applyCodeAction)) {
                throw new ConfigurationException(\sprintf('The "applyCodeAction" in %s must be the exact non-empty title of a code action.', $context));
            }
            $loaded['applyCodeAction'] = $applyCodeAction;
            $loaded['afterFix'] = $this->expectations($edit['afterFix'], 'afterFix', $context);
        }

        return $loaded;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function expectations(mixed $expect, string $key, string $context): array
    {
        if (!\is_array($expect) || [] === $expect || array_is_list($expect)) {
            throw new ConfigurationException(\sprintf('The "%s" in %s must be a non-empty map of features to expectations.', $key, $context));
        }
        $expectations = [];
        foreach ($expect as $feature => $expectation) {
            if (!\in_array($feature, self::FEATURES, true)) {
                throw new ConfigurationException(\sprintf('Unknown feature "%s" in the "%s" of %s, expected one of "%s".', $feature, $key, $context, implode('", "', self::FEATURES)));
            }
            $expectations[$feature] = $this->expectation($expectation, $feature, $context);
        }

        return $expectations;
    }

    /**
     * @return array<string, list<string>>
     */
    private function expectation(mixed $expectation, string $feature, string $context): array
    {
        if (!\is_array($expectation) || [] === $expectation || array_is_list($expectation)) {
            throw new ConfigurationException(\sprintf('The "%s" expectation of %s must be a map of "%s" lists.', $feature, $context, implode('", "', self::EXPECTATION_KEYS)));
        }
        foreach (array_keys($expectation) as $key) {
            if (!\in_array($key, self::EXPECTATION_KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in the "%s" expectation of %s.', $key, $feature, $context));
            }
        }
        $loaded = [];
        foreach (self::EXPECTATION_KEYS as $key) {
            if (\array_key_exists($key, $expectation)) {
                $loaded[$key] = $this->results($expectation[$key], $key, $feature, $context);
            }
        }
        if (\array_key_exists('equals', $loaded)) {
            if (\array_key_exists('includes', $loaded) || \array_key_exists('excludes', $loaded)) {
                throw new ConfigurationException(\sprintf('The "%s" expectation of %s must not combine "equals" with "includes" or "excludes".', $feature, $context));
            }

            return $loaded;
        }
        if ([] === ($loaded['includes'] ?? [])) {
            throw new ConfigurationException(\sprintf('The "%s" expectation of %s must declare "equals" or a non-empty "includes" list.', $feature, $context));
        }

        return $loaded;
    }

    /**
     * @return list<string>
     */
    private function results(mixed $results, string $key, string $feature, string $context): array
    {
        if (!\is_array($results) || !array_is_list($results)) {
            throw new ConfigurationException(\sprintf('The "%s" of the "%s" expectation of %s must be a list of strings.', $key, $feature, $context));
        }
        $loaded = [];
        foreach ($results as $result) {
            if (!\is_string($result) || '' === $result || !$this->isSafeText($result)) {
                throw new ConfigurationException(\sprintf('The "%s" of the "%s" expectation of %s must be a list of non-empty UTF-8 strings.', $key, $feature, $context));
            }
            $loaded[] = $result;
        }
        if ('equals' !== $key && \count(array_unique($loaded)) !== \count($loaded)) {
            throw new ConfigurationException(\sprintf('The "%s" of the "%s" expectation of %s must not repeat a result.', $key, $feature, $context));
        }

        return $loaded;
    }

    private function anchor(mixed $anchor, string $context): string
    {
        if (!\is_string($anchor) || '' === $anchor || !$this->isSafeText($anchor)) {
            throw new ConfigurationException(\sprintf('The "anchor" in %s must be a non-empty UTF-8 source excerpt.', $context));
        }

        return $anchor;
    }

    private function offset(mixed $offset, string $anchor, string $context): int
    {
        if (!\is_int($offset) || 0 > $offset || $offset > \strlen($anchor)) {
            throw new ConfigurationException(\sprintf('The "offset" in %s must be a byte offset within its %d byte anchor.', $context, \strlen($anchor)));
        }
        if ($offset < \strlen($anchor) && 0x80 === (\ord($anchor[$offset]) & 0xC0)) {
            throw new ConfigurationException(\sprintf('The "offset" in %s must fall on a character boundary of its anchor.', $context));
        }

        return $offset;
    }

    private function relativePath(mixed $path, string $key, string $context): string
    {
        if (!\is_string($path) || '' === $path || !$this->isSafeText($path) || 1 === preg_match('{^[/~]|^[A-Za-z]:|\\\\|://|(?:^|/)\.\.?(?:/|$)|//|/$}', $path)) {
            throw new ConfigurationException(\sprintf('The "%s" in %s must be a relative path inside the project.', $key, $context));
        }

        return $path;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<DiagnosticEntry>
     */
    private function diagnostics(array $data, string $path): array
    {
        if (!\array_key_exists('diagnostics', $data)) {
            throw new ConfigurationException(\sprintf('The manifest in "%s" must declare a "diagnostics" baseline, possibly empty.', $path));
        }
        $diagnostics = $data['diagnostics'];
        if (!\is_array($diagnostics) || !array_is_list($diagnostics)) {
            throw new ConfigurationException(\sprintf('The "diagnostics" in "%s" must be a list of baseline entries.', $path));
        }
        $entries = [];
        foreach ($diagnostics as $index => $entry) {
            $entries[] = $this->diagnostic($entry, $index, $path);
        }
        usort($entries, static fn (array $left, array $right): int => self::diagnosticOrder($left) <=> self::diagnosticOrder($right));

        return $entries;
    }

    /**
     * @return DiagnosticEntry
     */
    private function diagnostic(mixed $entry, int $index, string $path): array
    {
        $context = \sprintf('diagnostic #%d of "%s"', $index, $path);
        if (!\is_array($entry) || [] === $entry || array_is_list($entry)) {
            throw new ConfigurationException(\sprintf('The %s must be an object.', $context));
        }
        foreach (array_keys($entry) as $key) {
            if (!\in_array($key, self::DIAGNOSTIC_KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in %s.', $key, $context));
            }
        }
        $code = $entry['code'] ?? null;
        if (!\is_string($code) || '' === $code || !$this->isSafeText($code)) {
            throw new ConfigurationException(\sprintf('The "code" in %s must be a non-empty diagnostic code.', $context));
        }
        $severity = $entry['severity'] ?? null;
        if (!\is_int($severity) || 1 > $severity || self::MAX_SEVERITY < $severity) {
            throw new ConfigurationException(\sprintf('The "severity" in %s must be between 1 and %d.', $context, self::MAX_SEVERITY));
        }

        return [
            'path' => $this->relativePath($entry['path'] ?? null, 'path', $context),
            'code' => $code,
            'severity' => $severity,
            'range' => $this->range($entry['range'] ?? null, $context),
        ];
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private function range(mixed $range, string $context): array
    {
        if (!\is_array($range) || ['end', 'start'] !== $this->sortedKeys($range)) {
            throw new ConfigurationException(\sprintf('The "range" in %s must declare a "start" and an "end" position.', $context));
        }
        $start = $this->position($range['start'] ?? null, 'start', $context);
        $end = $this->position($range['end'] ?? null, 'end', $context);
        if ([$start['line'], $start['character']] > [$end['line'], $end['character']]) {
            throw new ConfigurationException(\sprintf('The "range" in %s must not end before it starts.', $context));
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{line: int, character: int}
     */
    private function position(mixed $position, string $key, string $context): array
    {
        if (!\is_array($position) || ['character', 'line'] !== $this->sortedKeys($position)
            || !\is_int($position['line']) || !\is_int($position['character'])
            || 0 > $position['line'] || 0 > $position['character']
        ) {
            throw new ConfigurationException(\sprintf('The "%s" of the "range" in %s must declare non-negative "line" and "character" numbers.', $key, $context));
        }

        return ['line' => $position['line'], 'character' => $position['character']];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array-key>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    private function isSafeText(string $value): bool
    {
        return !str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }

    /**
     * @param DiagnosticEntry $entry
     *
     * @return list<string|int>
     */
    private static function diagnosticOrder(array $entry): array
    {
        $range = $entry['range'];

        return [$entry['path'], $range['start']['line'], $range['start']['character'], $range['end']['line'], $range['end']['character'], $entry['severity'], $entry['code']];
    }
}
