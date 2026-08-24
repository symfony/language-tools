<?php

namespace Symfony\Lsp\Check;

final class CheckProjectResult
{
    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $runtime
     */
    public function __construct(
        public readonly string $id,
        public readonly string $environment,
        public readonly string $mode,
        public readonly ?string $modeReason,
        public readonly array $source,
        public readonly array $runtime,
        public readonly bool $complete,
    ) {
    }

    public function withComplete(bool $complete): self
    {
        return new self(
            $this->id,
            $this->environment,
            $this->mode,
            $this->modeReason,
            $this->source,
            $this->runtime,
            $complete,
        );
    }
}
