<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\Range;

final class MessengerHandlerSignature
{
    public function __construct(
        public readonly string $className,
        public readonly string $method,
        public readonly Range $range,
    ) {
    }
}
