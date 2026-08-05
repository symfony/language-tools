<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\SidecarTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;

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
        $peer = new JsonRpcPeer(new ContentLengthJsonRpcTransport($input, $output), $logger);
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onUnhandledError(static function (\Throwable $error) use ($logger): void {
            $logger->error($error);
        });

        $container = new ContainerBuilder();
        $container->setParameter('server.version', $version);
        $container->setParameter('bridge.source', \dirname(__DIR__, 2).'/resources/bridge.php');
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/resources'));
        $loader->load('services.php');
        $container->compile();
        $container->set(JsonRpcPeer::class, $peer);
        $container->set(JsonRpcDispatcher::class, $dispatcher);
        $container->set(ServerLogger::class, $logger);
        $container->set(TreeSitterParserInterface::class, $this->treeSitterParser());

        /** @var LanguageServer $server */
        $server = $container->get(LanguageServer::class);

        return $server;
    }

    private function treeSitterParser(): TreeSitterParserInterface
    {
        $decoder = new TreeSitterResultDecoder();
        if (\function_exists('symfony_lsp_tree_sitter_parse')) {
            return new NativeTreeSitterParser($decoder);
        }

        $configuredSidecar = getenv('SYMFONY_LSP_TREE_SITTER');
        $sidecar = false !== $configuredSidecar && '' !== $configuredSidecar
            ? $configuredSidecar
            : \dirname(\PHP_BINARY).'/symfony-lsp-tree-sitter'.('Windows' === \PHP_OS_FAMILY ? '.exe' : '');

        return new SidecarTreeSitterParser($sidecar, $decoder);
    }
}
