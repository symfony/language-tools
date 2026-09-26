<?php

namespace Symfony\Lsp\Parser;

final class DelimiterState
{
    /** @param list<DelimiterOpening> $openDelimiters */
    public function __construct(
        public readonly array $openDelimiters,
        public readonly ?DelimiterString $openString,
    ) {
    }

    public function innermostDelimiter(): ?DelimiterOpening
    {
        return [] === $this->openDelimiters ? null : $this->openDelimiters[array_key_last($this->openDelimiters)];
    }
}
