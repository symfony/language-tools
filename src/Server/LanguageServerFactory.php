<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Fabpot\JsonRpc\JsonRpcValueDecoding;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Lsp\Check\CheckClient;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Check\CheckProgressReporter;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Progress\ProgressReporterInterface;

final class LanguageServerFactory
{
    private readonly ServerVersion $serverVersion;

    /** @var array<array-key, mixed> */
    private readonly array $defaultPhpCommand;

    /** @param array<array-key, mixed> $defaultPhpCommand */
    public function __construct(
        ?ServerVersion $serverVersion = null,
        array $defaultPhpCommand = ['php'],
        private readonly string $releaseMetadataUrl = '',
    ) {
        $this->serverVersion = $serverVersion ?? new ServerVersion();
        $this->defaultPhpCommand = $defaultPhpCommand;
    }

    public function create(ReadableStream $input, WritableStream $output, ?WritableStream $errorOutput = null): LanguageServer
    {
        $logger = new ServerLogger($errorOutput, new SensitiveDataRedactor(new Utf8StringTruncator()));
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
        $container->compile();
        $container->set(ServerLogger::class, new ServerLogger($errorOutput, new SensitiveDataRedactor(new Utf8StringTruncator())));

        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);

        return $command;
    }

    private function container(): ContainerBuilder
    {
        $container = (new ContainerFactory())->create($this->serverVersion->value());
        $container->setParameter('runtime.default_php_command', $this->defaultPhpCommand);
        $container->setParameter('runtime.release_metadata_url', $this->releaseMetadataUrl);

        return $container;
    }
}
