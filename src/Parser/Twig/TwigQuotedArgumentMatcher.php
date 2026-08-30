<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\QuotedArgumentMatcher;

final class TwigQuotedArgumentMatcher extends QuotedArgumentMatcher
{
    protected function decode(string $raw, bool $single): string
    {
        return TwigStringDecoder::decode($raw);
    }
}
