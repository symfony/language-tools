<?php

namespace Symfony\Lsp\Feature\Twig;

use PHPStan\PhpDocParser\Parser\TokenIterator;

/** phpdoc-parser recomputes offsets from the first token, which makes large array shapes quadratic to parse. */
final class TwigCallablePhpDocTokens extends TokenIterator
{
    /** @var list<int>|null */
    private ?array $offsets = null;

    public function currentTokenOffset(): int
    {
        if (null === $this->offsets) {
            $offset = 0;
            $this->offsets = [];
            foreach ($this->getTokens() as [$value]) {
                $this->offsets[] = $offset;
                $offset += \strlen($value);
            }
            $this->offsets[] = $offset;
        }

        return $this->offsets[$this->currentTokenIndex()];
    }
}
