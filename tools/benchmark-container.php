<?php

namespace Symfony\Lsp\Tools;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\JsonRpcValueDecoding;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;

function installBenchmarkSyntheticServices(ContainerBuilder $container): void
{
    $peer = new JsonRpcPeer(
        new ContentLengthJsonRpcTransport(new ReadableBuffer(''), new WritableBuffer()),
        valueDecoding: JsonRpcValueDecoding::AssociativeArrays,
    );
    $container->set(JsonRpcPeer::class, $peer);
    $container->set(JsonRpcDispatcher::class, new JsonRpcDispatcher($peer));
    $container->set(ServerLogger::class, new ServerLogger(null, new SensitiveDataRedactor()));
}
