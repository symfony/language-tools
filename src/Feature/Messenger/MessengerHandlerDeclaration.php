<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerHandlerDeclaration
{
    public function __construct(
        public readonly string $message,
        public readonly string $bus,
        public readonly string $service,
        public readonly string $className,
        public readonly string $method,
        public readonly int $priority,
        public readonly ?string $fromTransport,
    ) {
    }
}
