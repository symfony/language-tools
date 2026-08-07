<?php

namespace Symfony\Lsp\Tests\Client;

use Amp\ByteStream\ReadableBuffer;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\StreamJsonRpcTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Protocol\JsonRpcValueNormalizer;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

use function Amp\async;
use function Amp\delay;

final class JsonRpcClientTest extends TestCase
{
    public function testNormalizesObjectResponsesForLspConsumers(): void
    {
        $peer = new JsonRpcPeer(new StreamJsonRpcTransport(
            new ReadableBuffer('{"jsonrpc":"2.0","id":1,"result":{"items":[{"name":"one"}]}}'),
            new CapturingWritableStream(),
        ));
        $client = new JsonRpcClient($peer, new JsonRpcValueNormalizer());
        $response = async(static fn (): mixed => $client->request('workspace/configuration', []));
        delay(0);

        $peer->listen();

        self::assertSame(['items' => [['name' => 'one']]], $response->await());
    }
}
