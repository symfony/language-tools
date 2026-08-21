<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\JsonRpcValueDecoding;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Filesystem\Path;

final class LanguageServerFactory
{
    private readonly ServerVersion $serverVersion;

    public function __construct(?ServerVersion $serverVersion = null)
    {
        $this->serverVersion = $serverVersion ?? new ServerVersion();
    }

    public function create(ReadableStream $input, WritableStream $output, ?WritableStream $errorOutput = null): LanguageServer
    {
        $version = $this->serverVersion->value();
        $logger = new ServerLogger($errorOutput);
        $peer = new JsonRpcPeer(
            new ContentLengthJsonRpcTransport($input, $output),
            trafficLogger: $logger,
            valueDecoding: JsonRpcValueDecoding::AssociativeArrays,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onUnhandledError(static function (\Throwable $error) use ($logger): void {
            $logger->error($error);
        });

        $resources = Path::join(\dirname(__DIR__, 2), 'resources');
        $container = new ContainerBuilder();
        $container->setParameter('server.version', $version);
        $container->setParameter('bridge.source', Path::join($resources, 'bridge.php'));
        $loader = new PhpFileLoader($container, new FileLocator($resources));
        $loader->load('services.php');
        $container->compile();
        $container->set(JsonRpcPeer::class, $peer);
        $container->set(JsonRpcDispatcher::class, $dispatcher);
        $container->set(ServerLogger::class, $logger);

        /** @var LanguageServer $server */
        $server = $container->get(LanguageServer::class);

        return $server;
    }
}
