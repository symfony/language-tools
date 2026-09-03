<?php

namespace Symfony\Lsp\Check;

final class CheckProfileProject
{
    /**
     * @param array<string, float|null> $phasesMilliseconds
     * @param array<string, float>      $diagnosticProvidersMilliseconds
     * @param array<string, float>      $slowestFilesMilliseconds
     */
    public function __construct(
        public readonly string $id,
        public readonly int $files,
        public readonly array $phasesMilliseconds,
        public readonly array $diagnosticProvidersMilliseconds,
        public readonly array $slowestFilesMilliseconds,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'files' => $this->files,
            'phasesMilliseconds' => $this->phasesMilliseconds,
            'diagnosticProvidersMilliseconds' => $this->diagnosticProvidersMilliseconds,
            'slowestFilesMilliseconds' => $this->slowestFilesMilliseconds,
        ];
    }
}
