<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodReceiver
{
    public function __construct(
        public readonly PhpMethodReceiverKind $kind,
        public readonly ?string $name,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
