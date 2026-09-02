<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;

final class PartialParseDiagnosticFilter
{
    private const FILTERED_CODES = [
        'console.unknown_argument' => true,
        'console.unknown_option' => true,
        'event.invalid_listener_method' => true,
        'messenger.invalid_handler_signature' => true,
    ];

    public function __construct(private readonly SourceOverlayHealthRegistry $health)
    {
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    public function filter(Document $document, array $diagnostics): array
    {
        if ('php' !== $document->languageId || !$this->health->isDegraded($document->uri)) {
            return $diagnostics;
        }

        return array_values(array_filter($diagnostics, static function (array $diagnostic): bool {
            $code = $diagnostic['code'] ?? null;

            return !\is_string($code) || !isset(self::FILTERED_CODES[$code]);
        }));
    }

    /**
     * @param list<CollectedDiagnostic> $diagnostics
     *
     * @return list<CollectedDiagnostic>
     */
    public function filterCollected(Document $document, array $diagnostics): array
    {
        if ('php' !== $document->languageId || !$this->health->isDegraded($document->uri)) {
            return $diagnostics;
        }

        return array_values(array_filter($diagnostics, static function (CollectedDiagnostic $diagnostic): bool {
            $code = $diagnostic->diagnostic['code'] ?? null;

            return !\is_string($code) || !isset(self::FILTERED_CODES[$code]);
        }));
    }
}
