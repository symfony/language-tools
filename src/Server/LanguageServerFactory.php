<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\ProjectRouteSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteDefinitionHandler;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteHoverHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferencesHandler;
use Symfony\Lsp\Feature\Route\RouteRenameHandler;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
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
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

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
        $routeDeclarationIndexes = new RouteDeclarationIndexRegistry();
        $routeReferenceIndexes = new RouteReferenceIndexRegistry();
        $client = new JsonRpcClient($peer);
        $bridgeInstaller = new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'dev');
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeInitializer = new ProjectRuntimeInitializer(
            $bridgeInstaller,
            new NativeProcessRunner(),
            $routeIndexes,
            $runtimeConfiguration,
        );
        $workspaceTrust = new WorkspaceTrust();
        $documentContextResolver = new DocumentContextResolver($documents, $projects);
        $routeReferenceExtractor = new RouteReferenceExtractor($positionConverter);
        $phpRouteDeclarationExtractor = new PhpRouteDeclarationExtractor($positionConverter);
        $yamlRouteDeclarationExtractor = new YamlRouteDeclarationExtractor($positionConverter);
        $routeSymbolResolver = new RouteSymbolResolver(
            $positionConverter,
            $routeReferenceExtractor,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
        );
        $routeSourceIndexer = new ProjectRouteSourceIndexer(
            $projects,
            $routeDeclarationIndexes,
            $routeReferenceIndexes,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
            $routeReferenceExtractor,
        );
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager(
                $client,
                $workspaceTrust,
                $runtimeInitializer,
            ),
            $runtimeConfiguration,
        );

        return new LanguageServer(
            $peer,
            new JsonRpcDispatcher($peer),
            new ServerState(),
            $workspaceConfiguration,
            new DocumentSynchronizer($documents, $positionConverter),
            new RouteCompletionHandler($documentContextResolver, $positionConverter, $routeIndexes),
            new RouteHoverHandler($documentContextResolver, $positionConverter, $routeIndexes),
            new RouteDiagnosticPublisher(
                $client,
                $documents,
                $projects,
                $routeIndexes,
                $routeReferenceExtractor,
            ),
            new RouteDefinitionHandler(
                $documentContextResolver,
                $positionConverter,
                $routeDeclarationIndexes,
            ),
            new RouteReferencesHandler(
                $documentContextResolver,
                $routeSymbolResolver,
                $routeReferenceIndexes,
                $routeDeclarationIndexes,
            ),
            new RouteRenameHandler(
                $documentContextResolver,
                $routeSymbolResolver,
                $routeReferenceIndexes,
                $routeDeclarationIndexes,
                $routeIndexes,
            ),
            new ProjectRuntimeRefresher($projects, $workspaceTrust, $runtimeInitializer),
            $routeSourceIndexer,
        );
    }
}
