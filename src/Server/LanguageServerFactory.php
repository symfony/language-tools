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
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDefinitionHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDiagnosticProvider;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionHoverHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReferencesHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionRenameHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexer;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\ServiceCompletionHandler;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\ProjectRouteSnapshotLoader;
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
use Symfony\Lsp\Feature\Translation\ProjectTranslationSnapshotLoader;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationProvider;
use Symfony\Lsp\Feature\Translation\TranslationRenameHandler;
use Symfony\Lsp\Feature\Translation\TranslationSourceIndexer;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TemplateSourceIndexer;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
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
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
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
        $serviceIndexes = new ServiceIndexRegistry();
        $parameterIndexes = new ParameterIndexRegistry();
        $dependencyInjectionSourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $templateIndexes = new TemplateIndexRegistry();
        $translationIndexes = new TranslationIndexRegistry();
        $translationConfiguration = new TranslationConfigurationRegistry();
        $routeDeclarationIndexes = new RouteDeclarationIndexRegistry();
        $routeReferenceIndexes = new RouteReferenceIndexRegistry();
        $client = new JsonRpcClient($peer);
        $workspaceTrust = new WorkspaceTrust();
        $documentContextResolver = new DocumentContextResolver($documents, $projects);
        $routeReferenceExtractor = new RouteReferenceExtractor($positionConverter);
        $twigRouteReferenceExtractor = new TwigRouteReferenceExtractor($positionConverter);
        $phpRouteDeclarationExtractor = new PhpRouteDeclarationExtractor($positionConverter);
        $yamlRouteDeclarationExtractor = new YamlRouteDeclarationExtractor($positionConverter);
        $yamlDependencyInjectionExtractor = new YamlDependencyInjectionExtractor($positionConverter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($positionConverter);
        $classExtractor = new PhpClassDeclarationExtractor($positionConverter);
        $dependencyInjectionSymbolResolver = new DependencyInjectionSymbolResolver(
            $positionConverter,
            $yamlDependencyInjectionExtractor,
            $autowireExtractor,
        );
        $templateReferenceExtractor = new TemplateReferenceExtractor($positionConverter);
        $templateNavigation = new TemplateNavigationProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $templateReferenceExtractor,
            $templateIndexes,
        );
        $translationExtractor = new TranslationExtractor($positionConverter);
        $translationProvider = new TranslationProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $translationIndexes,
            $translationExtractor,
            $translationConfiguration,
        );
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
            new DependencyInjectionDiagnosticProvider(
                $documents,
                $projects,
                $serviceIndexes,
                $parameterIndexes,
                $dependencyInjectionSourceIndexes,
                $yamlDependencyInjectionExtractor,
                $autowireExtractor,
            ),
            $templateNavigation,
            $translationProvider,
        );
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeInitializer = new ObservedRuntimeInitializer(
            new ReportingRuntimeInitializer(
                new StatusRuntimeInitializer(
                    new ProjectRuntimeInitializer(
                        new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'dev'),
                        new NativeProcessRunner(),
                        new RuntimeSnapshotLoaderRegistry(
                            new ProjectRouteSnapshotLoader($routeIndexes),
                            new ProjectServiceSnapshotLoader($serviceIndexes, $parameterIndexes),
                            new ProjectTemplateSnapshotLoader($templateIndexes),
                            new ProjectTranslationSnapshotLoader($translationIndexes),
                        ),
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
        $dependencyInjectionSourceIndexer = new DependencyInjectionSourceIndexer(
            $dependencyInjectionSourceIndexes,
            $yamlDependencyInjectionExtractor,
            $autowireExtractor,
            $classExtractor,
        );
        $sourceScanner = new ApplicationSourceScanner(
            $projects,
            $documents,
            $statuses,
            $routeSourceIndexer,
            $dependencyInjectionSourceIndexer,
            new TemplateSourceIndexer($templateIndexes, $templateReferenceExtractor),
            new TranslationSourceIndexer($translationIndexes, $translationExtractor),
        );
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager($client, $workspaceTrust, $runtimeInitializer),
            $runtimeConfiguration,
            new ProjectSettings($client, $projects, $translationConfiguration),
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
            new CompletionProviderRegistry(
                $routeCompletion,
                new ServiceCompletionHandler(
                    $documentContextResolver,
                    $positionConverter,
                    $serviceIndexes,
                    $parameterIndexes,
                    $dependencyInjectionSourceIndexes,
                ),
                new TemplateCompletionHandler(
                    $documentContextResolver,
                    $positionConverter,
                    $templateIndexes,
                ),
                $translationProvider,
            ),
            new HoverProviderRegistry(
                $routeHover,
                new DependencyInjectionHoverHandler(
                    $documentContextResolver,
                    $dependencyInjectionSymbolResolver,
                    $serviceIndexes,
                    $parameterIndexes,
                    $dependencyInjectionSourceIndexes,
                ),
                $templateNavigation,
                $translationProvider,
            ),
            $diagnosticProviders,
            new DefinitionProviderRegistry(
                $routeDefinition,
                new DependencyInjectionDefinitionHandler(
                    $documentContextResolver,
                    $dependencyInjectionSymbolResolver,
                    $dependencyInjectionSourceIndexes,
                    $serviceIndexes,
                ),
                $templateNavigation,
                $translationProvider,
            ),
            new DocumentLinkProviderRegistry(
                new RouteDocumentLinkHandler(
                    $documents,
                    $projects,
                    $routeDeclarationIndexes,
                    $routeReferenceExtractor,
                    $twigRouteReferenceExtractor,
                ),
                $templateNavigation,
            ),
            new ReferencesProviderRegistry(
                $routeReferences,
                new DependencyInjectionReferencesHandler(
                    $documentContextResolver,
                    $dependencyInjectionSymbolResolver,
                    $dependencyInjectionSourceIndexes,
                ),
                $templateNavigation,
                $translationProvider,
            ),
            new RenameProviderRegistry(
                $routeRename,
                new DependencyInjectionRenameHandler(
                    $documentContextResolver,
                    $dependencyInjectionSymbolResolver,
                    $dependencyInjectionSourceIndexes,
                    $serviceIndexes,
                    $parameterIndexes,
                ),
                new TranslationRenameHandler(
                    $documentContextResolver,
                    $positionConverter,
                    $translationExtractor,
                    $translationIndexes,
                ),
            ),
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
