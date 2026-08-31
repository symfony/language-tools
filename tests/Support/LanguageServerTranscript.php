<?php

namespace Symfony\Lsp\Tests\Support;

final class LanguageServerTranscript
{
    /** @param list<array<string, mixed>> $messages */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $raw,
        public readonly array $messages,
    ) {
    }
}
