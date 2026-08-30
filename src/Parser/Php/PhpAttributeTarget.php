<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttributeTarget
{
    public function __construct(
        public readonly PhpAttributeTargetKind $kind,
        public readonly string $className,
        public readonly ?string $memberName,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
    ) {
    }
}
