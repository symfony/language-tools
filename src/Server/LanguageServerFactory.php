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
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Check\CheckClient;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Check\CheckProgressReporter;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Runtime\SerializedRuntimeInitializer;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;

final class LanguageServerFactory
{
    private readonly ServerVersion $serverVersion;

    public function __construct(?ServerVersion $serverVersion = null)
    {
        $this->serverVersion = $serverVersion ?? new ServerVersion();
    }

    public function create(ReadableStream $input, WritableStream $output, ?WritableStream $errorOutput = null): LanguageServer
    {
        $logger = new ServerLogger($errorOutput, new SensitiveDataRedactor());
        $peer = new JsonRpcPeer(
            new ContentLengthJsonRpcTransport($input, $output),
            trafficLogger: $logger,
            valueDecoding: JsonRpcValueDecoding::AssociativeArrays,
        );
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onUnhandledError(static function (\Throwable $error) use ($logger): void {
            $logger->error($error);
        });

        $container = $this->container();
        $container->compile();
        $container->set(JsonRpcPeer::class, $peer);
        $container->set(JsonRpcDispatcher::class, $dispatcher);
        $container->set(ServerLogger::class, $logger);

        /** @var LanguageServer $server */
        $server = $container->get(LanguageServer::class);

        return $server;
    }

    public function createCheck(?WritableStream $errorOutput = null): CheckCommand
    {
        $container = $this->container();
        $container->setAlias(ClientInterface::class, CheckClient::class);
        $container->setAlias(ProgressReporterInterface::class, CheckProgressReporter::class);
        $container->getDefinition(SerializedRuntimeInitializer::class)
            ->setArgument('$initializer', new Reference(StatusRuntimeInitializer::class));
        $container->compile();
        $container->set(ServerLogger::class, new ServerLogger($errorOutput, new SensitiveDataRedactor()));

        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);

        return $command;
    }

    private function container(): ContainerBuilder
    {
        $resources = Path::join(\dirname(__DIR__, 2), 'resources');
        $container = new ContainerBuilder();
        $container->setParameter('server.version', $this->serverVersion->value());
        $container->setParameter('bridge.source', Path::join($resources, 'bridge.php'));
        $loader = new PhpFileLoader($container, new FileLocator($resources));
        $loader->load('services.php');

        return $container;
    }
}
