<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodCall
{
    use PhpArgumentAccess;

    /**
     * @param list<PhpArgument> $arguments
     */
    public function __construct(
        public readonly string $receiver,
        public readonly PhpMethodReceiver $receiverContext,
        public readonly string $method,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly array $arguments,
        public readonly ?string $className,
        public readonly ?string $enclosingMethod,
        public readonly ?int $scopeStartOffset,
    ) {
    }
}
