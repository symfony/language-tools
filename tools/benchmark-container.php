<?php

namespace Symfony\Lsp\Tools;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\WritableBuffer;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\JsonRpcValueDecoding;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;

function createBenchmarkContainer(string $serverVersion): ContainerBuilder
{
    $resources = \dirname(__DIR__).'/resources';
    $container = new ContainerBuilder();
    $container->setParameter('server.version', $serverVersion);
    $container->setParameter('bridge.source', $resources.'/bridge.php');
    (new PhpFileLoader($container, new FileLocator($resources)))->load('services.php');

    return $container;
}

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
