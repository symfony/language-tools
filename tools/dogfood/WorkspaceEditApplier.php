<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Path;

/**
 * Applies a workspace edit to open document overlays, rejecting anything it cannot apply as a whole.
 */
final class WorkspaceEditApplier
{
    private const PROTECTED_DIRECTORIES = ['vendor/', 'var/'];

    public function __construct(private readonly Utf16Positions $positions)
    {
    }

    /**
     * @param array<array-key, mixed> $edit
     * @param array<string, string>   $overlays open document texts indexed by URI
     *
     * @return array<string, string> new texts of the changed documents
     *
     * @throws ScenarioStepException when the edit cannot be applied safely and completely
     */
    public function apply(array $edit, array $overlays, string $projectRoot): array
    {
        $changes = $edit['changes'] ?? null;
        $documentChanges = $edit['documentChanges'] ?? null;
        if (\is_array($changes) && \is_array($documentChanges)) {
            throw new ScenarioStepException('The workspace edit declares both "changes" and "documentChanges".');
        }
        $edits = \is_array($documentChanges) ? $this->documentChangeEdits($documentChanges) : $this->changeEdits($changes);
        if ([] === $edits) {
            throw new ScenarioStepException('The workspace edit does not contain any text edit.');
        }
        $texts = [];
        foreach ($edits as $uri => $documentEdits) {
            $this->assertApplicable($uri, $projectRoot);
            $text = $overlays[$uri] ?? throw new ScenarioStepException(\sprintf('The workspace edit changes "%s", which is not open.', $uri));
            $texts[$uri] = $this->applyTo($text, $documentEdits, $uri);
        }

        return $texts;
    }

    /**
     * @param array<array-key, mixed> $documentChanges
     *
     * @return array<string, list<array{range: array<array-key, mixed>, newText: string}>>
     */
    private function documentChangeEdits(array $documentChanges): array
    {
        $edits = [];
        foreach ($documentChanges as $change) {
            if (!\is_array($change)) {
                throw new ScenarioStepException('The workspace edit contains a malformed document change.');
            }
            if (\is_string($change['kind'] ?? null)) {
                throw new ScenarioStepException(\sprintf('The workspace edit requests the unsupported "%s" resource operation.', $change['kind']));
            }
            $textDocument = $change['textDocument'] ?? null;
            $uri = \is_array($textDocument) ? ($textDocument['uri'] ?? null) : null;
            if (!\is_string($uri) || !\is_array($change['edits'] ?? null)) {
                throw new ScenarioStepException('The workspace edit contains a document change without a document or edits.');
            }
            foreach ($change['edits'] as $documentEdit) {
                $edits[$uri][] = $this->edit($documentEdit);
            }
        }

        return $edits;
    }

    /**
     * @return array<string, list<array{range: array<array-key, mixed>, newText: string}>>
     */
    private function changeEdits(mixed $changes): array
    {
        if (!\is_array($changes)) {
            throw new ScenarioStepException('The workspace edit does not declare "changes" or "documentChanges".');
        }
        $edits = [];
        foreach ($changes as $uri => $documentEdits) {
            if (!\is_string($uri) || !\is_array($documentEdits)) {
                throw new ScenarioStepException('The workspace edit contains a malformed change.');
            }
            foreach ($documentEdits as $documentEdit) {
                $edits[$uri][] = $this->edit($documentEdit);
            }
        }

        return $edits;
    }

    /**
     * @return array{range: array<array-key, mixed>, newText: string}
     */
    private function edit(mixed $edit): array
    {
        if (!\is_array($edit) || !\is_array($edit['range'] ?? null) || !\is_string($edit['newText'] ?? null)) {
            throw new ScenarioStepException('The workspace edit contains a malformed text edit.');
        }

        return ['range' => $edit['range'], 'newText' => $edit['newText']];
    }

    private function assertApplicable(string $uri, string $projectRoot): void
    {
        if (!str_starts_with($uri, 'file://')) {
            throw new ScenarioStepException(\sprintf('The workspace edit changes "%s", which is not a file.', $uri));
        }
        $projectRoot = Path::canonicalize($projectRoot);
        $path = Path::canonicalize(rawurldecode(substr($uri, \strlen('file://'))));
        if (!Path::isBasePath($projectRoot, $path)) {
            throw new ScenarioStepException(\sprintf('The workspace edit changes "%s", which is outside the application.', $uri));
        }
        $relativePath = Path::makeRelative($path, $projectRoot);
        foreach (self::PROTECTED_DIRECTORIES as $directory) {
            if (str_starts_with($relativePath, $directory)) {
                throw new ScenarioStepException(\sprintf('The workspace edit changes "%s", which is dependency-owned or generated.', $relativePath));
            }
        }
    }

    /**
     * @param list<array{range: array<array-key, mixed>, newText: string}> $edits
     */
    private function applyTo(string $text, array $edits, string $uri): string
    {
        $replacements = [];
        foreach ($edits as $edit) {
            $range = $edit['range'];
            $start = $this->positions->byteOffset($text, \is_array($range['start'] ?? null) ? $range['start'] : []);
            $end = $this->positions->byteOffset($text, \is_array($range['end'] ?? null) ? $range['end'] : []);
            if ($start > $end) {
                throw new ScenarioStepException(\sprintf('The workspace edit contains an inverted range for "%s".', $uri));
            }
            $replacements[] = [$start, $end, $edit['newText']];
        }
        usort($replacements, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        for ($index = 1; $index < \count($replacements); ++$index) {
            if ($replacements[$index][0] < $replacements[$index - 1][1]) {
                throw new ScenarioStepException(\sprintf('The workspace edit contains overlapping edits for "%s".', $uri));
            }
        }
        foreach (array_reverse($replacements) as [$start, $end, $newText]) {
            $text = substr($text, 0, $start).$newText.substr($text, $end);
        }

        return $text;
    }
}
