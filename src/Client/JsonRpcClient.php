<?php

namespace Symfony\Lsp\Client;

use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Protocol\JsonRpcValueNormalizer;

final class JsonRpcClient implements ClientInterface
{
    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly JsonRpcValueNormalizer $jsonRpcValueNormalizer,
    ) {
    }

    public function request(string $method, array $params): mixed
    {
        return $this->jsonRpcValueNormalizer->normalize($this->peer->request($method, $params)->await());
    }

    public function notify(string $method, array $params): void
    {
        $this->peer->notify($method, $params);
    }
}
