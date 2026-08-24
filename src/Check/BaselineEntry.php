<?php

namespace Symfony\Lsp\Check;

final class BaselineEntry
{
    public function __construct(
        public readonly string $project,
        public readonly string $path,
        public readonly string $code,
        public readonly string $severity,
        public readonly string $source,
        public readonly string $message,
        public readonly string $fingerprint,
        public readonly int $occurrence,
    ) {
    }

    /** @return array{project: string, path: string, code: string, severity: string, source: string, message: string, fingerprint: string, occurrence: int} */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'path' => $this->path,
            'code' => $this->code,
            'severity' => $this->severity,
            'source' => $this->source,
            'message' => $this->message,
            'fingerprint' => $this->fingerprint,
            'occurrence' => $this->occurrence,
        ];
    }
}
