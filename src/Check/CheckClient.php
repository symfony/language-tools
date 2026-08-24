<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Client\ClientInterface;

final class CheckClient implements ClientInterface
{
    public function request(string $method, array $params): never
    {
        throw new \LogicException('The LSP client is unavailable in check mode.');
    }

    public function notify(string $method, array $params): never
    {
        throw new \LogicException('The LSP client is unavailable in check mode.');
    }
}
