<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\ContentLengthJsonRpcTransport;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Microsoft\PhpParser\Parser;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationProvider;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
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
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceIndexer;
use Symfony\Lsp\Feature\Environment\ProjectEnvironmentSnapshotLoader;
use Symfony\Lsp\Feature\Event\EventExtractor;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Feature\Event\EventProvider;
use Symfony\Lsp\Feature\Event\EventSourceIndexer;
use Symfony\Lsp\Feature\Event\EventSourceIndexRegistry;
use Symfony\Lsp\Feature\Event\ProjectEventSnapshotLoader;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerExtractor;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerProvider;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexer;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Feature\Messenger\ProjectMessengerSnapshotLoader;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\ProjectRouteSnapshotLoader;
use Symfony\Lsp\Feature\Route\ProjectRouteSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteCodeActionProvider;
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
use Symfony\Lsp\Feature\Security\ProjectSecuritySnapshotLoader;
use Symfony\Lsp\Feature\Security\SecurityExtractor;
use Symfony\Lsp\Feature\Security\SecurityIndexRegistry;
use Symfony\Lsp\Feature\Security\SecurityProvider;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexer;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexRegistry;
use Symfony\Lsp\Feature\Translation\ProjectTranslationSnapshotLoader;
use Symfony\Lsp\Feature\Translation\TranslationCodeActionProvider;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationProvider;
use Symfony\Lsp\Feature\Translation\TranslationRenameHandler;
use Symfony\Lsp\Feature\Translation\TranslationSourceIndexer;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TemplateSourceIndexer;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentSourceIndexer;
use Symfony\Lsp\Feature\Twig\TwigVariableProvider;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\SidecarTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
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
use Symfony\Lsp\Runtime\ProgressRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
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
        $version = $this->serverVersion->value();
        $logger = new ServerLogger($errorOutput);
        $peer = new JsonRpcPeer(new ContentLengthJsonRpcTransport($input, $output), $logger);
        $dispatcher = new JsonRpcDispatcher($peer);
        $dispatcher->onUnhandledError(static function (\Throwable $error) use ($logger): void {
            $logger->error($error);
        });

        $documents = new DocumentStore();
        $positionConverter = new PositionConverter();
        $projects = new ProjectRegistry();
        $statuses = new ProjectIndexStatusRegistry();
        $routeIndexes = new RouteIndexRegistry();
        $serviceIndexes = new ServiceIndexRegistry();
        $parameterIndexes = new ParameterIndexRegistry();
        $dependencyInjectionSourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $templateIndexes = new TemplateIndexRegistry();
        $twigComponentIndexes = new TwigComponentIndexRegistry();
        $translationIndexes = new TranslationIndexRegistry();
        $translationConfiguration = new TranslationConfigurationRegistry();
        $environmentIndexes = new EnvironmentIndexRegistry();
        $configurationIndexes = new ConfigurationIndexRegistry();
        $routeDeclarationIndexes = new RouteDeclarationIndexRegistry();
        $routeReferenceIndexes = new RouteReferenceIndexRegistry();
        $messengerIndexes = new MessengerIndexRegistry();
        $messengerSourceIndexes = new MessengerSourceIndexRegistry();
        $eventIndexes = new EventIndexRegistry();
        $eventSourceIndexes = new EventSourceIndexRegistry();
        $securityIndexes = new SecurityIndexRegistry();
        $securitySourceIndexes = new SecuritySourceIndexRegistry();
        $client = new JsonRpcClient($peer);
        $progress = new WorkDoneProgressReporter($client);
        $workspaceTrust = new WorkspaceTrust();
        $documentContextResolver = new DocumentContextResolver($documents, $projects);
        $routeReferenceExtractor = new RouteReferenceExtractor($positionConverter);
        $treeSitterParser = $this->treeSitterParser();
        $twigParser = new TwigDocumentParser($treeSitterParser);
        $twigRouteReferenceExtractor = new TwigRouteReferenceExtractor($positionConverter, $twigParser);
        $phpParser = new TolerantPhpParser(new Parser());
        $phpRouteDeclarationExtractor = new PhpRouteDeclarationExtractor($positionConverter, $phpParser);
        $yamlRouteDeclarationExtractor = new YamlRouteDeclarationExtractor($positionConverter);
        $yamlDependencyInjectionExtractor = new YamlDependencyInjectionExtractor($positionConverter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($positionConverter, $phpParser);
        $classExtractor = new PhpClassDeclarationExtractor($positionConverter);
        $yamlConfigurationParser = new YamlConfigurationParser($positionConverter, new YamlDocumentParser($treeSitterParser));
        $messengerExtractor = new MessengerExtractor($positionConverter);
        $messengerProvider = new MessengerProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $messengerIndexes,
            $messengerSourceIndexes,
            $messengerExtractor,
            $classExtractor,
            $dependencyInjectionSourceIndexes,
        );
        $eventExtractor = new EventExtractor($positionConverter);
        $eventProvider = new EventProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $eventIndexes,
            $eventSourceIndexes,
            $eventExtractor,
            $classExtractor,
            $dependencyInjectionSourceIndexes,
        );
        $securityExtractor = new SecurityExtractor($positionConverter, $yamlConfigurationParser);
        $securityProvider = new SecurityProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $securityIndexes,
            $securitySourceIndexes,
            $securityExtractor,
        );
        $dependencyInjectionSymbolResolver = new DependencyInjectionSymbolResolver(
            $positionConverter,
            $yamlDependencyInjectionExtractor,
            $autowireExtractor,
        );
        $templateReferenceExtractor = new TemplateReferenceExtractor($positionConverter, $twigParser);
        $templateNavigation = new TemplateNavigationProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $templateReferenceExtractor,
            $templateIndexes,
        );
        $twigVariableProvider = new TwigVariableProvider(
            $documentContextResolver,
            $positionConverter,
            $templateIndexes,
            $twigComponentIndexes,
        );
        $twigComponentExtractor = new TwigComponentExtractor($positionConverter);
        $twigComponentProvider = new TwigComponentProvider(
            $documents,
            $projects,
            $positionConverter,
            $twigComponentIndexes,
            $twigComponentExtractor,
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
        $environmentExtractor = new EnvironmentExtractor($positionConverter);
        $environmentProvider = new EnvironmentProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $environmentIndexes,
            $environmentExtractor,
        );
        $configurationProvider = new ConfigurationProvider(
            $documentContextResolver,
            $documents,
            $projects,
            $positionConverter,
            $configurationIndexes,
            $yamlConfigurationParser,
            $environmentIndexes,
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
            $twigComponentProvider,
            $translationProvider,
            $environmentProvider,
            $configurationProvider,
            $messengerProvider,
            $eventProvider,
            $securityProvider,
        );
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeInitializer = new ObservedRuntimeInitializer(
            new ReportingRuntimeInitializer(
                new ProgressRuntimeInitializer(
                    new StatusRuntimeInitializer(
                        new ProjectRuntimeInitializer(
                            new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', $version),
                            new NativeProcessRunner(),
                            new RuntimeSnapshotLoaderRegistry(
                                new ProjectRouteSnapshotLoader($routeIndexes),
                                new ProjectServiceSnapshotLoader($serviceIndexes, $parameterIndexes),
                                new ProjectTemplateSnapshotLoader($templateIndexes),
                                new ProjectTranslationSnapshotLoader($translationIndexes),
                                new ProjectEnvironmentSnapshotLoader($environmentIndexes),
                                new ProjectConfigurationSnapshotLoader($configurationIndexes),
                                new ProjectMessengerSnapshotLoader($messengerIndexes),
                                new ProjectEventSnapshotLoader($eventIndexes),
                                new ProjectSecuritySnapshotLoader($securityIndexes),
                            ),
                            $runtimeConfiguration,
                        ),
                        $statuses,
                    ),
                    $progress,
                ),
                $client,
                $statuses,
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
            $progress,
            new PersistentSourceIndexStore($version),
            new SourceIndexPayloadCodec(),
            $routeSourceIndexer,
            $dependencyInjectionSourceIndexer,
            new TemplateSourceIndexer($templateIndexes, $templateReferenceExtractor),
            new TwigComponentSourceIndexer($twigComponentIndexes, $twigComponentExtractor),
            new TranslationSourceIndexer($translationIndexes, $translationExtractor),
            new EnvironmentSourceIndexer($environmentIndexes, $environmentExtractor),
            new MessengerSourceIndexer($messengerSourceIndexes, $messengerExtractor),
            new EventSourceIndexer($eventSourceIndexes, $eventExtractor),
            new SecuritySourceIndexer($securitySourceIndexes, $securityExtractor),
        );
        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $projects,
            new WorkspaceTrustManager($client, $workspaceTrust, $runtimeInitializer),
            $runtimeConfiguration,
            new ProjectSettings($client, $projects, $translationConfiguration, $runtimeConfiguration),
            $positionConverter,
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
            $dispatcher,
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
                $twigVariableProvider,
                $twigComponentProvider,
                $translationProvider,
                $environmentProvider,
                $configurationProvider,
                $messengerProvider,
                $eventProvider,
                $securityProvider,
            ),
            new CodeActionProviderRegistry(
                new RouteCodeActionProvider(
                    $documents,
                    $projects,
                    $positionConverter,
                    $routeIndexes,
                    $routeReferenceExtractor,
                    $twigRouteReferenceExtractor,
                ),
                new TemplateCodeActionProvider(
                    $documents,
                    $projects,
                    $templateReferenceExtractor,
                    $templateIndexes,
                ),
                new TranslationCodeActionProvider(
                    $documents,
                    $projects,
                    $positionConverter,
                    $translationExtractor,
                    $translationIndexes,
                ),
            ),
            new CodeLensProviderRegistry($messengerProvider, $eventProvider, $twigComponentProvider),
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
                $twigVariableProvider,
                $twigComponentProvider,
                $translationProvider,
                $environmentProvider,
                $configurationProvider,
                $messengerProvider,
                $eventProvider,
                $securityProvider,
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
                $twigComponentProvider,
                $translationProvider,
                $environmentProvider,
                $messengerProvider,
                $eventProvider,
                $securityProvider,
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
                $configurationProvider,
            ),
            new ReferencesProviderRegistry(
                $routeReferences,
                new DependencyInjectionReferencesHandler(
                    $documentContextResolver,
                    $dependencyInjectionSymbolResolver,
                    $dependencyInjectionSourceIndexes,
                ),
                $templateNavigation,
                $twigComponentProvider,
                $translationProvider,
                $environmentProvider,
                $messengerProvider,
                $eventProvider,
                $securityProvider,
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
                $runtimeConfiguration,
            ),
            $sourceScanner,
            new IndexCommandHandler(
                $projects,
                $workspaceTrust,
                $sourceScanner,
                $runtimeInitializer,
                $statuses,
                $runtimeConfiguration,
            ),
            $logger,
            $progress,
            $version,
        );
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
