<?php

namespace Symfony\Lsp\Client;

use Fabpot\JsonRpc\JsonRpcPeer;

final class JsonRpcClient implements ClientInterface
{
    public function __construct(
        private readonly JsonRpcPeer $peer,
    ) {
    }

    public function request(string $method, array $params): mixed
    {
        return $this->peer->request($method, $params)->await();
    }
}
