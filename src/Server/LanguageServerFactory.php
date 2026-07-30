<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderRegistry;
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
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Runtime\ObservedRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;

final class LanguageServerFactory
{
    public function create(ReadableStream $input, WritableStream $output): LanguageServer
    {
        $peer = new JsonRpcPeer(new ContentLengthJsonRpcTransport($input, $output));

        $documents = new DocumentStore();
        $positionConverter = new PositionConverter();
        $projects = new ProjectRegistry();
        $statuses = new ProjectIndexStatusRegistry();
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
        $routeDiagnostics = new RouteDiagnosticPublisher(
            $documents,
            $projects,
            $routeIndexes,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
        );
        $diagnosticProviders = new DiagnosticProviderRegistry(
            $client,
            $documents,
            $projects,
            $routeDiagnostics,
        );
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeInitializer = new ObservedRuntimeInitializer(
            new ReportingRuntimeInitializer(
                new StatusRuntimeInitializer(
                    new ProjectRuntimeInitializer(
                        new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'dev'),
                        new NativeProcessRunner(),
                        $routeIndexes,
                        $runtimeConfiguration,
                    ),
                    $statuses,
                ),
                $client,
            ),
            $diagnosticProviders,
        );
        $routeSymbolResolver = new RouteSymbolResolver(
            $positionConverter,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
        );
        $routeSourceIndexer = new ProjectRouteSourceIndexer(
            $routeDeclarationIndexes,
            $routeReferenceIndexes,
            $phpRouteDeclarationExtractor,
            $yamlRouteDeclarationExtractor,
            $routeReferenceExtractor,
            $twigRouteReferenceExtractor,
        );
        $sourceScanner = new ApplicationSourceScanner(
            $projects,
            $documents,
            $statuses,
            $routeSourceIndexer,
        );
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager($client, $workspaceTrust, $runtimeInitializer),
            $runtimeConfiguration,
        );
        $routeCompletion = new RouteCompletionHandler($documentContextResolver, $positionConverter, $routeIndexes);
        $routeHover = new RouteHoverHandler(
            $documentContextResolver,
            $positionConverter,
            $routeIndexes,
            $twigRouteReferenceExtractor,
        );
        $routeDefinition = new RouteDefinitionHandler(
            $documentContextResolver,
            $routeSymbolResolver,
            $routeDeclarationIndexes,
        );
        $routeReferences = new RouteReferencesHandler(
            $documentContextResolver,
            $routeSymbolResolver,
            $routeReferenceIndexes,
            $routeDeclarationIndexes,
        );
        $routeRename = new RouteRenameHandler(
            $documentContextResolver,
            $routeSymbolResolver,
            $routeReferenceIndexes,
            $routeDeclarationIndexes,
            $routeIndexes,
        );

        return new LanguageServer(
            $peer,
            new JsonRpcDispatcher($peer),
            new ServerState(),
            $workspaceConfiguration,
            new DocumentSynchronizer($documents, $positionConverter),
            new CompletionProviderRegistry($routeCompletion),
            new HoverProviderRegistry($routeHover),
            $diagnosticProviders,
            new DefinitionProviderRegistry($routeDefinition),
            new RouteDocumentLinkHandler(
                $documents,
                $projects,
                $routeDeclarationIndexes,
                $routeReferenceExtractor,
                $twigRouteReferenceExtractor,
            ),
            new ReferencesProviderRegistry($routeReferences),
            new RenameProviderRegistry($routeRename),
            new ProjectRuntimeRefresher(
                $projects,
                $workspaceTrust,
                new DebouncedRuntimeRefreshScheduler($runtimeInitializer),
                $statuses,
            ),
            $sourceScanner,
            new IndexCommandHandler(
                $projects,
                $workspaceTrust,
                $sourceScanner,
                $runtimeInitializer,
                $statuses,
            ),
        );
    }
}
