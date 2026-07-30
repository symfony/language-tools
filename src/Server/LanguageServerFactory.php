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
use Symfony\Lsp\Feature\Route\RouteDocumentLinkHandler;
use Symfony\Lsp\Feature\Route\RouteHoverHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferencesHandler;
use Symfony\Lsp\Feature\Route\RouteRenameHandler;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
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
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Runtime\ObservedRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
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
        $workspaceTrust = new WorkspaceTrust();
        $documentContextResolver = new DocumentContextResolver($documents, $projects);
        $routeReferenceExtractor = new RouteReferenceExtractor($positionConverter);
        $twigRouteReferenceExtractor = new TwigRouteReferenceExtractor($positionConverter);
        $phpRouteDeclarationExtractor = new PhpRouteDeclarationExtractor($positionConverter);
        $yamlRouteDeclarationExtractor = new YamlRouteDeclarationExtractor($positionConverter);
        $routeDiagnosticPublisher = new RouteDiagnosticPublisher(
            $client,
            $documents,
            $projects,
            $routeIndexes,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
        );
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeInitializer = new ObservedRuntimeInitializer(
            new ReportingRuntimeInitializer(
                new ProjectRuntimeInitializer(
                    new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'dev'),
                    new NativeProcessRunner(),
                    $routeIndexes,
                    $runtimeConfiguration,
                ),
                $client,
            ),
            $routeDiagnosticPublisher,
        );
        $routeSymbolResolver = new RouteSymbolResolver(
            $positionConverter,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
        );
        $routeSourceIndexer = new ProjectRouteSourceIndexer(
            $projects,
            $documents,
            $routeDeclarationIndexes,
            $routeReferenceIndexes,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
        );
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager($client, $workspaceTrust, $runtimeInitializer),
            $runtimeConfiguration,
        );

        return new LanguageServer(
            $peer,
            new JsonRpcDispatcher($peer),
            new ServerState(),
            $workspaceConfiguration,
            new DocumentSynchronizer($documents, $positionConverter),
            new RouteCompletionHandler($documentContextResolver, $positionConverter, $routeIndexes),
            new RouteHoverHandler(
                $documentContextResolver,
                $positionConverter,
                $routeIndexes,
                $twigRouteReferenceExtractor,
            ),
            $routeDiagnosticPublisher,
            new RouteDefinitionHandler(
                $documentContextResolver,
                $routeSymbolResolver,
                $routeDeclarationIndexes,
            ),
            new RouteDocumentLinkHandler(
                $documents,
                $projects,
                $routeDeclarationIndexes,
                $routeReferenceExtractor,
                $twigRouteReferenceExtractor,
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
            new ProjectRuntimeRefresher(
                $projects,
                $workspaceTrust,
                new DebouncedRuntimeRefreshScheduler($runtimeInitializer),
            ),
            $routeSourceIndexer,
        );
    }
}
