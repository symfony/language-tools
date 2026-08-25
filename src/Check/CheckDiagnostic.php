<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;

final class CheckDiagnostic
{
    public function __construct(
        public readonly string $project,
        public readonly string $path,
        public readonly string $workspacePath,
        public readonly int $startLine,
        public readonly int $startCharacter,
        public readonly int $endLine,
        public readonly int $endCharacter,
        public readonly int $severity,
        public readonly string $code,
        public readonly string $source,
        public readonly string $message,
        public readonly string $fingerprint,
        public readonly string $baselineState = 'active',
        public readonly ?string $provider = null,
    ) {
    }

    /** @param array<array-key, mixed> $diagnostic */
    public static function fromProtocol(CheckFile $file, string $projectId, string $text, array $diagnostic, PositionConverter $positions, ?string $provider = null): self
    {
        $range = $diagnostic['range'] ?? null;
        $start = \is_array($range) ? ($range['start'] ?? null) : null;
        $end = \is_array($range) ? ($range['end'] ?? null) : null;
        $severity = $diagnostic['severity'] ?? null;
        $code = $diagnostic['code'] ?? null;
        $source = $diagnostic['source'] ?? null;
        $message = $diagnostic['message'] ?? null;
        if (!\is_array($start)
            || !\is_int($start['line'] ?? null)
            || !\is_int($start['character'] ?? null)
            || !\is_array($end)
            || !\is_int($end['line'] ?? null)
            || !\is_int($end['character'] ?? null)
            || $start['line'] < 0
            || $start['character'] < 0
            || $end['line'] < 0
            || $end['character'] < 0
            || $end['line'] < $start['line']
            || ($end['line'] === $start['line'] && $end['character'] < $start['character'])
            || !\is_int($severity)
            || !\in_array($severity, [1, 2, 3, 4], true)
            || !\is_string($code)
            || !\is_string($source)
            || !\is_string($message)
        ) {
            throw new \UnexpectedValueException('A diagnostic provider returned an invalid diagnostic.');
        }

        $startPosition = new Position($start['line'], $start['character']);
        $endPosition = new Position($end['line'], $end['character']);
        $startOffset = $positions->toByteOffset($text, $startPosition);
        $endOffset = $positions->toByteOffset($text, $endPosition);
        $evidence = substr($text, $startOffset, max(0, $endOffset - $startOffset));
        if ('' === $evidence) {
            $lines = explode("\n", $text);
            $evidence = trim($lines[$start['line']] ?? '');
        }
        $fingerprint = hash('sha256', json_encode([
            $projectId,
            $file->projectPath,
            $code,
            $severity,
            $source,
            $message,
            hash('sha256', $evidence),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE));

        return new self(
            $projectId,
            $file->projectPath,
            $file->workspacePath,
            $start['line'],
            $start['character'],
            $end['line'],
            $end['character'],
            $severity,
            $code,
            $source,
            $message,
            $fingerprint,
            provider: $provider,
        );
    }

    public function withBaselineState(string $state): self
    {
        return new self(
            $this->project,
            $this->path,
            $this->workspacePath,
            $this->startLine,
            $this->startCharacter,
            $this->endLine,
            $this->endCharacter,
            $this->severity,
            $this->code,
            $this->source,
            $this->message,
            $this->fingerprint,
            $state,
            $this->provider,
        );
    }

    public function severityName(): string
    {
        return match ($this->severity) {
            1 => 'error',
            2 => 'warning',
            3 => 'information',
            4 => 'hint',
            default => 'unknown',
        };
    }
}
