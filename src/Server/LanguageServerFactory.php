<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Protocol\ContentLengthMessageReader;
use Symfony\Lsp\Protocol\ContentLengthMessageWriter;
use Symfony\Lsp\Protocol\ContentLengthReadableStream;
use Symfony\Lsp\Protocol\ContentLengthWritableStream;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;

final class LanguageServerFactory
{
    public function create(ReadableStream $input, WritableStream $output): LanguageServer
    {
        $input = new ContentLengthReadableStream($input, new ContentLengthMessageReader($input));
        $output = new ContentLengthWritableStream($output, new ContentLengthMessageWriter($output));
        $peer = new JsonRpcPeer($input, $output);

        $documents = new DocumentStore();
        $positionConverter = new PositionConverter();
        $projects = new ProjectRegistry();
        $routeIndexes = new RouteIndexRegistry();
        $bridgeInstaller = new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'dev');
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager(
                new JsonRpcClient($peer),
                new WorkspaceTrust(),
                new ProjectRuntimeInitializer(
                    $bridgeInstaller,
                    new NativeProcessRunner(),
                    $routeIndexes,
                ),
            ),
        );

        return new LanguageServer(
            $peer,
            new JsonRpcDispatcher($peer),
            new ServerState(),
            $workspaceConfiguration,
            new DocumentSynchronizer($documents, $positionConverter),
            new RouteCompletionHandler($documents, $positionConverter, $projects, $routeIndexes),
        );
    }
}
