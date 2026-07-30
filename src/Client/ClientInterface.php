<?php

namespace Symfony\Lsp\Client;

interface ClientInterface
{
    /**
     * @param array<array-key, mixed> $params
     */
    public function request(string $method, array $params): mixed;

    /**
     * @param array<array-key, mixed> $params
     */
    public function notify(string $method, array $params): void;
}
