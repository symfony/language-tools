<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Path;

/**
 * Projects language server answers to deterministic, comparable entries and
 * matches them against scenario expectations.
 *
 * Entry shapes name files relative to the project root and keep the zero-based
 * UTF-16 coordinates the server answered with:
 *
 *   completion    label
 *   hover         whitespace-normalized markdown
 *   definition    relative/path.php:startLine:startCharacter-endLine:endCharacter
 *   references    relative/path.php:startLine:startCharacter-endLine:endCharacter
 *   documentLink  startLine:startCharacter-endLine:endCharacter=>relative/target.twig#fragment
 *   codeLens      startLine:startCharacter-endLine:endCharacter=>title
 *   prepareRename startLine:startCharacter-endLine:endCharacter
 *   rename        relative/path.php:startLine:startCharacter-endLine:endCharacter=>newText
 *   codeAction    title, or title=>relative/path.php:range=>newText once per edit
 *   diagnostics   code:severity:startLine:startCharacter-endLine:endCharacter
 *
 * Only the document links covering the requested position are projected. A
 * file outside the project projects as "<outside>/name" instead of a machine
 * path, and an answer that cannot be projected as "<invalid>".
 */
final class ResponseAssertions
{
    public const METHODS = [
        'completion',
        'hover',
        'definition',
        'references',
        'documentLink',
        'codeLens',
        'prepareRename',
        'rename',
        'codeAction',
        'diagnostics',
    ];
    public const EXPECTATION_KEYS = ['equals', 'includes', 'excludes'];

    private const INVALID = '<invalid>';
    private const OUTSIDE = '<outside>';

    /**
     * Answers keep the order the server returned them in and keep duplicates,
     * so a repeated answer stays visible; comparison is order-insensitive.
     *
     * @param array{line: int, character: int} $position
     *
     * @return list<string>
     */
    public function project(string $method, mixed $result, string $projectRoot, string $documentUri, array $position): array
    {
        $root = Path::canonicalize($projectRoot);

        return match ($this->method($method)) {
            'completion' => $this->completions($result),
            'hover' => $this->hover($result),
            'definition', 'references' => $this->locations($result, $root),
            'documentLink' => $this->documentLinks($result, $root, $position),
            'codeLens' => $this->codeLenses($result),
            'prepareRename' => $this->prepareRename($result),
            'rename' => $this->workspaceEdit($result, $root),
            'codeAction' => $this->codeActions($result, $root),
            'diagnostics' => $this->diagnostics($result),
        };
    }

    /**
     * Failure explanations only name entries the scenario itself asked for:
     * answers the server returned on its own are reported as counts so hover
     * texts and replacement values never reach a report.
     *
     * @param list<string>            $actual
     * @param array<array-key, mixed> $expectation
     *
     * @return list<string>
     */
    public function compare(string $method, array $actual, array $expectation): array
    {
        $method = $this->method($method);
        $failures = [];
        foreach (array_diff(array_keys($expectation), self::EXPECTATION_KEYS) as $key) {
            $failures[] = \sprintf('The %s expectation uses the unsupported key "%s".', $method, (string) $key);
        }
        $equals = $this->expected($expectation, 'equals', $method, $failures);
        $includes = $this->expected($expectation, 'includes', $method, $failures);
        $excludes = $this->expected($expectation, 'excludes', $method, $failures);
        if (null === $equals && [] === ($includes ?? [])) {
            $failures[] = \sprintf('The %s expectation must assert "equals" or a non-empty "includes".', $method);
        }
        if (null !== $equals) {
            $failures = [...$failures, ...$this->compareEquals($method, $actual, $equals)];
        }
        if ('hover' === $method) {
            return [...$failures, ...$this->compareHoverText($actual, $includes ?? [], $excludes ?? [])];
        }

        return [...$failures, ...$this->compareEntries($method, $actual, $includes ?? [], $excludes ?? [])];
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected
     *
     * @return list<string>
     */
    private function compareEquals(string $method, array $actual, array $expected): array
    {
        if ([] === $expected) {
            return [] === $actual ? [] : [\sprintf('Expected no %s answer, got %d.', $method, \count($actual))];
        }
        $failures = [];
        $counts = array_count_values($actual);
        $expectedCounts = array_count_values($expected);
        foreach ($expectedCounts as $entry => $count) {
            $found = $counts[$entry] ?? 0;
            if ($count !== $found) {
                $failures[] = 1 === $count && 0 === $found
                    ? \sprintf('Expected the %s answers to contain "%s", which is missing.', $method, $entry)
                    : \sprintf('Expected the %s answers to contain "%s" %d time(s), got %d.', $method, $entry, $count, $found);
            }
        }
        $unexpected = 0;
        foreach ($counts as $entry => $found) {
            $unexpected += isset($expectedCounts[$entry]) ? 0 : $found;
        }
        if (0 < $unexpected) {
            $failures[] = \sprintf('Expected the %s answers to match exactly, got %d unexpected answer(s).', $method, $unexpected);
        }

        return $failures;
    }

    /**
     * @param list<string> $actual
     * @param list<string> $includes
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    private function compareEntries(string $method, array $actual, array $includes, array $excludes): array
    {
        $failures = [];
        $counts = array_count_values($actual);
        foreach (array_count_values($includes) as $entry => $count) {
            $found = $counts[$entry] ?? 0;
            if ($found >= $count) {
                continue;
            }
            $failures[] = 1 === $count
                ? \sprintf('Expected the %s answers to contain "%s", which is missing.', $method, $entry)
                : \sprintf('Expected the %s answers to contain "%s" %d time(s), got %d.', $method, $entry, $count, $found);
        }
        foreach (array_unique($excludes) as $entry) {
            if (isset($counts[$entry])) {
                $failures[] = \sprintf('Expected the %s answers not to contain "%s", which was returned %d time(s).', $method, $entry, $counts[$entry]);
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $actual
     * @param list<string> $includes
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    private function compareHoverText(array $actual, array $includes, array $excludes): array
    {
        $text = implode(' ', $actual);
        $failures = [];
        foreach ($includes as $entry) {
            if (!str_contains($text, $this->normalize($entry))) {
                $failures[] = \sprintf('Expected the hover text to contain "%s", which is missing.', $entry);
            }
        }
        foreach ($excludes as $entry) {
            if (str_contains($text, $this->normalize($entry))) {
                $failures[] = \sprintf('Expected the hover text not to contain "%s", which is present.', $entry);
            }
        }

        return $failures;
    }

    /**
     * @param array<array-key, mixed> $expectation
     * @param list<string>            $failures
     *
     * @return list<string>|null
     */
    private function expected(array $expectation, string $key, string $method, array &$failures): ?array
    {
        if (!\array_key_exists($key, $expectation)) {
            return null;
        }
        $entries = $expectation[$key];
        $invalid = \sprintf('The %s expectation key "%s" must be a list of strings.', $method, $key);
        if (!\is_array($entries) || !array_is_list($entries)) {
            $failures[] = $invalid;

            return null;
        }
        $strings = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry)) {
                $failures[] = $invalid;

                return null;
            }
            $strings[] = $entry;
        }

        return $strings;
    }

    /** @return list<string> */
    private function completions(mixed $result): array
    {
        if (null === $result) {
            return [];
        }
        $items = \is_array($result) && !array_is_list($result) ? ($result['items'] ?? null) : $result;
        if (!\is_array($items) || !array_is_list($items)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach ($items as $item) {
            $label = \is_array($item) ? ($item['label'] ?? null) : null;
            $entries[] = \is_string($label) ? $this->escape($label) : self::INVALID;
        }

        return $entries;
    }

    /** @return list<string> */
    private function hover(mixed $result): array
    {
        if (null === $result) {
            return [];
        }
        $contents = \is_array($result) ? ($result['contents'] ?? null) : null;
        $text = $this->markdown($contents);

        return [null === $text ? self::INVALID : $text];
    }

    private function markdown(mixed $contents): ?string
    {
        if (\is_string($contents)) {
            return $this->normalize($contents);
        }
        if (!\is_array($contents)) {
            return null;
        }
        if (\is_string($contents['value'] ?? null)) {
            return $this->normalize($contents['value']);
        }
        if (!array_is_list($contents)) {
            return null;
        }
        $parts = [];
        foreach ($contents as $part) {
            if (null === $rendered = $this->markdown($part)) {
                return null;
            }
            $parts[] = $rendered;
        }

        return $this->normalize(implode(' ', $parts));
    }

    /** @return list<string> */
    private function locations(mixed $result, string $root): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach (array_is_list($result) ? $result : [$result] as $location) {
            $entries[] = $this->location($location, $root);
        }

        return $entries;
    }

    private function location(mixed $location, string $root): string
    {
        if (!\is_array($location)) {
            return self::INVALID;
        }
        $uri = $location['uri'] ?? $location['targetUri'] ?? null;
        $range = $location['range'] ?? $location['targetSelectionRange'] ?? $location['targetRange'] ?? null;
        $formatted = $this->range($range);
        if (!\is_string($uri) || null === $formatted) {
            return self::INVALID;
        }

        return $this->relative($uri, $root).':'.$formatted;
    }

    /**
     * @param array{line: int, character: int} $position
     *
     * @return list<string>
     */
    private function documentLinks(mixed $result, string $root, array $position): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result) || !array_is_list($result)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach ($result as $link) {
            $range = \is_array($link) ? ($link['range'] ?? null) : null;
            $covers = $this->covers($range, $position);
            if (false === $covers) {
                continue;
            }
            $formatted = $this->range($range);
            $target = \is_array($link) ? ($link['target'] ?? null) : null;
            $entries[] = null === $formatted || !\is_string($target)
                ? self::INVALID
                : $formatted.'=>'.$this->relative($target, $root);
        }

        return $entries;
    }

    /** @return list<string> */
    private function codeLenses(mixed $result): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result) || !array_is_list($result)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach ($result as $lens) {
            $formatted = $this->range(\is_array($lens) ? ($lens['range'] ?? null) : null);
            $command = \is_array($lens) ? ($lens['command'] ?? null) : null;
            $title = \is_array($command) ? ($command['title'] ?? null) : null;
            $entries[] = null === $formatted
                ? self::INVALID
                : $formatted.(\is_string($title) ? '=>'.$this->escape($title) : '');
        }

        return $entries;
    }

    /** @return list<string> */
    private function prepareRename(mixed $result): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result)) {
            return [self::INVALID];
        }
        if (true === ($result['defaultBehavior'] ?? null)) {
            return ['defaultBehavior'];
        }
        $formatted = $this->range($result['range'] ?? $result);

        return [$formatted ?? self::INVALID];
    }

    /** @return list<string> */
    private function workspaceEdit(mixed $edit, string $root): array
    {
        if (null === $edit) {
            return [];
        }
        if (!\is_array($edit)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach (\is_array($edit['changes'] ?? null) ? $edit['changes'] : [] as $uri => $edits) {
            $entries = [...$entries, ...$this->textEdits($edits, (string) $uri, $root)];
        }
        foreach (\is_array($edit['documentChanges'] ?? null) ? $edit['documentChanges'] : [] as $change) {
            if (!\is_array($change)) {
                $entries[] = self::INVALID;
                continue;
            }
            $kind = $change['kind'] ?? null;
            if (\is_string($kind)) {
                $entries[] = $this->resourceOperation($change, $kind, $root);
                continue;
            }
            $document = $change['textDocument'] ?? null;
            $uri = \is_array($document) ? ($document['uri'] ?? null) : null;
            $entries = \is_string($uri)
                ? [...$entries, ...$this->textEdits($change['edits'] ?? null, $uri, $root)]
                : [...$entries, self::INVALID];
        }

        return $entries;
    }

    /** @return list<string> */
    private function textEdits(mixed $edits, string $uri, string $root): array
    {
        if (!\is_array($edits) || !array_is_list($edits)) {
            return [self::INVALID];
        }
        $path = $this->relative($uri, $root);
        $entries = [];
        foreach ($edits as $edit) {
            $range = \is_array($edit) ? $this->range($edit['range'] ?? null) : null;
            $newText = \is_array($edit) ? ($edit['newText'] ?? null) : null;
            $entries[] = null === $range || !\is_string($newText)
                ? self::INVALID
                : $path.':'.$range.'=>'.$this->escape($newText);
        }

        return $entries;
    }

    /** @param array<array-key, mixed> $change */
    private function resourceOperation(array $change, string $kind, string $root): string
    {
        $uris = [];
        foreach ('rename' === $kind ? ['oldUri', 'newUri'] : ['uri'] as $key) {
            $uri = $change[$key] ?? null;
            if (!\is_string($uri)) {
                return self::INVALID;
            }
            $uris[] = $this->relative($uri, $root);
        }

        return $kind.':'.implode('=>', $uris);
    }

    /** @return list<string> */
    private function codeActions(mixed $result, string $root): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result) || !array_is_list($result)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach ($result as $action) {
            $title = \is_array($action) ? ($action['title'] ?? null) : null;
            if (!\is_string($title)) {
                $entries[] = self::INVALID;
                continue;
            }
            $title = $this->escape($title);
            $edits = $this->workspaceEdit($action['edit'] ?? null, $root);
            if ([] === $edits) {
                $entries[] = $title;
                continue;
            }
            foreach ($edits as $edit) {
                $entries[] = $title.'=>'.$edit;
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private function diagnostics(mixed $result): array
    {
        if (null === $result) {
            return [];
        }
        if (!\is_array($result) || !array_is_list($result)) {
            return [self::INVALID];
        }
        $entries = [];
        foreach ($result as $diagnostic) {
            $range = \is_array($diagnostic) ? $this->range($diagnostic['range'] ?? null) : null;
            if (!\is_array($diagnostic) || null === $range) {
                $entries[] = self::INVALID;
                continue;
            }
            $code = $diagnostic['code'] ?? null;
            $entries[] = \sprintf(
                '%s:%s:%s',
                \is_string($code) || \is_int($code) ? $this->escape((string) $code) : '<none>',
                $this->severity($diagnostic['severity'] ?? null),
                $range,
            );
        }

        return $entries;
    }

    private function severity(mixed $severity): string
    {
        return match ($severity) {
            1 => 'error',
            2 => 'warning',
            3 => 'information',
            4 => 'hint',
            default => 'unknown',
        };
    }

    private function range(mixed $range): ?string
    {
        if (!\is_array($range)) {
            return null;
        }
        $start = $this->position($range['start'] ?? null);
        $end = $this->position($range['end'] ?? null);

        return null === $start || null === $end ? null : $start.'-'.$end;
    }

    private function position(mixed $position): ?string
    {
        if (!\is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        return \is_int($line) && \is_int($character) && 0 <= $line && 0 <= $character ? $line.':'.$character : null;
    }

    /** @param array{line: int, character: int} $position */
    private function covers(mixed $range, array $position): ?bool
    {
        if (!\is_array($range) || null === $this->position($range['start'] ?? null) || null === $this->position($range['end'] ?? null)) {
            return null;
        }
        /** @var array{start: array{line: int, character: int}, end: array{line: int, character: int}} $range */
        $cursor = [$position['line'], $position['character']];

        return $cursor >= [$range['start']['line'], $range['start']['character']]
            && $cursor < [$range['end']['line'], $range['end']['character']];
    }

    private function relative(string $uri, string $root): string
    {
        [$uri, $fragment] = $this->splitFragment($uri);
        if (!str_starts_with($uri, 'file://')) {
            return $uri.$fragment;
        }
        $path = Path::canonicalize(str_replace('\\', '/', rawurldecode(substr($uri, \strlen('file://')))));
        if (preg_match('{^/[A-Za-z]:/}', $path)) {
            $path = substr($path, 1);
        }
        if ($path !== $root && !Path::isBasePath($root, $path)) {
            return self::OUTSIDE.'/'.basename($path).$fragment;
        }
        $relative = Path::makeRelative($path, $root);

        return ('' === $relative ? '.' : $relative).$fragment;
    }

    /** @return array{string, string} */
    private function splitFragment(string $uri): array
    {
        $position = strpos($uri, '#');

        return false === $position ? [$uri, ''] : [substr($uri, 0, $position), substr($uri, $position)];
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function escape(string $value): string
    {
        return strtr($value, ["\n" => '\n', "\r" => '\r', "\t" => '\t']);
    }

    /** @return value-of<self::METHODS> */
    private function method(string $method): string
    {
        $short = str_starts_with($method, 'textDocument/') ? substr($method, \strlen('textDocument/')) : $method;
        if (!\in_array($short, self::METHODS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported assertion method "%s".', $method));
        }

        return $short;
    }
}
