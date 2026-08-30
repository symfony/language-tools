<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttribute
{
    use PhpArgumentAccess;

    /**
     * @param list<PhpArgument>        $arguments
     * @param list<PhpAttributeTarget> $targets
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly array $targets,
    ) {
    }
}
