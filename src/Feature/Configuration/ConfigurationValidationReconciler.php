<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\SavedDocumentMatcher;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ConfigurationValidationReconciler
{
    private const PROVISIONAL_CODES = ['config.invalid_type', 'config.unknown_key', 'env.incompatible_type'];

    public function __construct(
        private readonly ConfigurationValidationRegistry $validations,
        private readonly SavedDocumentMatcher $savedDocuments,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
    ) {
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    public function applyValidation(Document $document, Project $project, string $relativePath, array $diagnostics): array
    {
        $validation = $this->validations->result($project);
        $validatedSavedContent = $this->runtimeConfiguration->runtimeIndexing($project)
            && $validation->environment === $this->runtimeConfiguration->environment($project)
            && $this->savedDocuments->matches($project, $document);
        if (!$validatedSavedContent || ConfigurationValidationResult::INVALID !== $validation->state) {
            return $this->provisional($diagnostics);
        }

        $diagnostics = $this->provisional($diagnostics, true);
        $vendorDiagnostic = $this->vendorDiagnostic($document, $relativePath, $validation);
        if (null === $vendorDiagnostic) {
            return $diagnostics;
        }

        return [$vendorDiagnostic['diagnostic'], ...array_values(array_filter(
            $diagnostics,
            function (array $diagnostic) use ($vendorDiagnostic): bool {
                $range = $diagnostic['range'] ?? null;

                return !\is_array($range)
                    || !$this->protocol->sameRange($vendorDiagnostic['range'], $range)
                    || $vendorDiagnostic['diagnostic']['code'] !== ($diagnostic['code'] ?? null);
            },
        ))];
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    private function provisional(array $diagnostics, bool $allErrors = false): array
    {
        foreach ($diagnostics as &$diagnostic) {
            if (($allErrors && 1 === ($diagnostic['severity'] ?? null))
                || \in_array($diagnostic['code'] ?? null, self::PROVISIONAL_CODES, true)
            ) {
                $diagnostic['severity'] = 2;
            }
        }
        unset($diagnostic);

        return $diagnostics;
    }

    /** @return array{diagnostic: array<array-key, mixed>, range: Range}|null */
    private function vendorDiagnostic(Document $document, string $relativePath, ConfigurationValidationResult $validation): ?array
    {
        if ('yaml' !== $validation->kind || $validation->file !== $relativePath) {
            return null;
        }
        $range = $this->validationRange($document, $validation);

        return ['diagnostic' => $this->protocol->diagnostic($range, 1, 'config.malformed_structure', 'The YAML configuration is invalid.'), 'range' => $range];
    }

    private function validationRange(Document $document, ConfigurationValidationResult $validation): Range
    {
        if (null === $validation->line) {
            return new Range(new Position(0, 0), new Position(0, 0));
        }
        $lines = explode("\n", $document->text);
        $line = min(max(0, $validation->line - 1), max(0, \count($lines) - 1));
        $start = 0;
        for ($index = 0; $index < $line; ++$index) {
            $start += \strlen($lines[$index]) + 1;
        }
        $end = $start + \strlen($lines[$line] ?? '');

        return new Range($this->converter->toPosition($document->text, $start), $this->converter->toPosition($document->text, $end));
    }
}
