<?php

namespace Symfony\Lsp\Tests\Support;

final class ProtocolMessageExpectation
{
    /** @param \Closure(array<string, mixed>): bool $predicate */
    public function __construct(
        public readonly string $description,
        public readonly \Closure $predicate,
    ) {
    }

    /** @param array<string, mixed> $message */
    public function matches(array $message): bool
    {
        return ($this->predicate)($message);
    }
}
