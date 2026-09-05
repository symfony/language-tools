<?php

use Amp\Sync\LocalKeyedMutex;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Microsoft\PhpParser\Parser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticSuppressor;
use Symfony\Lsp\Feature\Doctrine\DoctrineRelationshipCodeLensProvider;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentCompletionProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentDiagnosticProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentRelationshipProvider;
use Symfony\Lsp\Feature\Event\EventCodeLensProvider;
use Symfony\Lsp\Feature\Event\EventSubscriberMapAnalyzer;
use Symfony\Lsp\Feature\Event\EventYamlListenerAnalyzer;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerCodeLensProvider;
use Symfony\Lsp\Feature\PartialParseDiagnosticFilter;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Feature\Stimulus\StimulusCodeLensProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerNameNormalizer;
use Symfony\Lsp\Feature\Twig\LiveComponentEventProvider;
use Symfony\Lsp\Feature\Twig\TemplateCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableArgumentAnalyzer;
use Symfony\Lsp\Feature\Twig\TwigCallableCompletionProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableDiagnosticProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableRelationshipProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentCodeLensProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentCompletionProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentDiagnosticProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentRelationshipProvider;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolProvider;
use Symfony\Lsp\Feature\Twig\TwigVariableProvider;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Index\SourceIndexStoreInterface;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\LastResultPhpParser;
use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\LastResultTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlScalarDecoder;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\ProjectStateCleaner;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Runtime\ObservedRuntimeInitializer;
use Symfony\Lsp\Runtime\ProcessRunnerInterface;
use Symfony\Lsp\Runtime\ProgressRuntimeInitializer;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshSchedulerInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Runtime\SerializedRuntimeInitializer;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;
use Symfony\Lsp\Server\LanguageServer;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Server\WorkDoneProgressReporter;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('runtime.default_php_command', ['php'])
        ->set('runtime.release_metadata_url', '')
    ;

    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$serverVersion', param('server.version'))
        ->bind('$version', param('server.version'))
        ->bind('$bridgeSource', param('bridge.source'))
        ->bind('$defaultPhpCommand', param('runtime.default_php_command'))
        ->bind('$releaseMetadataUrl', param('runtime.release_metadata_url'))
    ;

    $providerTags = [
        CompletionProviderInterface::class => 'lsp.provider.completion',
        CodeActionProviderInterface::class => 'lsp.provider.code_action',
        HoverProviderInterface::class => 'lsp.provider.hover',
        DiagnosticProviderInterface::class => 'lsp.provider.diagnostic',
        DefinitionProviderInterface::class => 'lsp.provider.definition',
        DocumentLinkProviderInterface::class => 'lsp.provider.document_link',
        ReferencesProviderInterface::class => 'lsp.provider.references',
        RenameProviderInterface::class => 'lsp.provider.rename',
        SourceIndexProviderInterface::class => 'lsp.source_index_provider',
        RuntimeSnapshotLoaderInterface::class => 'lsp.runtime_snapshot_loader',
        ProjectStateInterface::class => 'lsp.project_state',
    ];
    foreach ($providerTags as $interface => $tag) {
        $services->instanceof($interface)->tag($tag);
    }

    $services->load('Symfony\\Lsp\\Feature\\', '../src/Feature/*Registry.php');
    $services->set(StimulusControllerNameNormalizer::class);
    $services->set(DiagnosticCollector::class);
    $services->set(DiagnosticSuppressor::class);
    $services->set(PartialParseDiagnosticFilter::class);
    $featureGroups = [
        'Route' => [],
        'DependencyInjection' => [],
        'Console' => [],
        'Twig' => [
            TemplateCompletionHandler::class,
            TemplateNavigationProvider::class,
            TemplateCodeActionProvider::class,
            TwigCallableArgumentAnalyzer::class,
            TwigCallableCompletionProvider::class,
            TwigCallableRelationshipProvider::class,
            TwigCallableDiagnosticProvider::class,
            TwigPhpSymbolProvider::class,
            TwigVariableProvider::class,
            TwigComponentCompletionProvider::class,
            TwigComponentRelationshipProvider::class,
            TwigComponentDiagnosticProvider::class,
            TwigComponentCodeLensProvider::class,
            LiveComponentEventProvider::class,
        ],
        'Translation' => [],
        'Environment' => [
            EnvironmentCompletionProvider::class,
            EnvironmentRelationshipProvider::class,
            EnvironmentDiagnosticProvider::class,
        ],
        'Configuration' => [],
        'Messenger' => [],
        'Event' => [
            EventYamlListenerAnalyzer::class,
            EventSubscriberMapAnalyzer::class,
        ],
        'Security' => [],
        'Metadata' => [],
        'Asset' => [],
        'Stimulus' => [],
        'Doctrine' => [],
    ];
    foreach ($featureGroups as $feature => $orderedServices) {
        foreach ($orderedServices as $orderedService) {
            $services->set($orderedService);
        }
        $services->load(
            'Symfony\\Lsp\\Feature\\'.$feature.'\\',
            '../src/Feature/'.$feature.'/*{Provider,Handler,Extractor,Indexer,Registry,Resolver,Loader,Publisher,Parser,Validator,Builder,Classifier,Analyzer,Reconciler,Lookup}.php',
        );
    }

    $services->load('Symfony\\Lsp\\Check\\', '../src/Check/*{Manager,Registry,Parser,Selector,Runner,Reporter,Profiler,Command,Client,Factory,Analyzer,Executor,Builder,Codec,Repository,Matcher,Tokenizer,Numberer}.php');
    $services->load('Symfony\\Lsp\\Client\\', '../src/Client/*Client.php');
    $services->load('Symfony\\Lsp\\Document\\', '../src/Document/*{Resolver,Store,Synchronizer,Converter,Reader}.php');
    $services->load('Symfony\\Lsp\\Index\\', '../src/Index/*{Scanner,Handler,Registry,Codec,Hasher,Enumerator,Pipeline,Processor,Manager,Resolver}.php');
    $services->set(PersistentSourceIndexStore::class);
    $services->load('Symfony\\Lsp\\Parser\\', '../src/Parser/**/*{Parser,Locator}.php');
    $services->set(TreeSitterResultDecoder::class);
    $services->set(YamlScalarDecoder::class);
    $services->set(BalancedDelimiterMatcher::class);
    $services->set(PhpCapturedReceiverResolver::class);
    $services->set(TwigCallArgumentResolver::class);
    $services->set(CommentParserRegistry::class)
        ->arg('$parsers', [
            'php' => service(PhpCommentParser::class),
            'twig' => service(TwigCommentParser::class),
            'yaml' => service(YamlCommentParser::class),
            'xml' => service(XmlCommentParser::class),
        ]);
    $services->load('Symfony\\Lsp\\Project\\', '../src/Project/*{Discovery,Registry,Resolver,Settings,Converter,Configuration,Trust,Manager,Matcher,Compiler,Cleaner}.php');
    $services->load('Symfony\\Lsp\\Protocol\\', '../src/Protocol/*Mapper.php');
    $services->load('Symfony\\Lsp\\Runtime\\', '../src/Runtime/*{Installer,Runner,Initializer,Refresher,Scheduler,Configuration,Registry,Planner,Mapper,Normalizer,Store,State}.php');
    $services->load('Symfony\\Lsp\\Server\\', '../src/Server/*{Server,Logger,State,Reporter,Watcher,Redactor,Truncator}.php');

    $services->set(Parser::class);
    $services->set(Filesystem::class);
    $services->set(LocalKeyedMutex::class);
    $services->set(JsonRpcPeer::class)->synthetic()->public();
    $services->set(JsonRpcDispatcher::class)->synthetic()->public();
    $services->set(ServerLogger::class)->synthetic()->public();

    $services->alias(ClientInterface::class, JsonRpcClient::class);
    $services->alias(PhpParserInterface::class, TolerantPhpParser::class);
    $services->get(LastResultPhpParser::class)
        ->decorate(PhpParserInterface::class)
        ->arg('$parser', service(LastResultPhpParser::class.'.inner'));
    $services->alias(TreeSitterParserInterface::class, NativeTreeSitterParser::class);
    $services->get(LastResultTreeSitterParser::class)
        ->decorate(TreeSitterParserInterface::class)
        ->arg('$parser', service(LastResultTreeSitterParser::class.'.inner'));
    $services->alias(ProcessRunnerInterface::class, NativeProcessRunner::class);
    $services->alias(ProgressReporterInterface::class, WorkDoneProgressReporter::class);
    $services->alias(RuntimeRefreshObserverInterface::class, DiagnosticProviderRegistry::class);
    $services->alias(RuntimeRefreshSchedulerInterface::class, DebouncedRuntimeRefreshScheduler::class);
    $services->alias(SourceIndexStoreInterface::class, PersistentSourceIndexStore::class);

    $services->get(StatusRuntimeInitializer::class)
        ->arg('$initializer', service(ProjectRuntimeInitializer::class));
    $services->get(ProgressRuntimeInitializer::class)
        ->arg('$initializer', service(StatusRuntimeInitializer::class));
    $services->get(ReportingRuntimeInitializer::class)
        ->arg('$initializer', service(ProgressRuntimeInitializer::class));
    $services->get(ObservedRuntimeInitializer::class)
        ->arg('$initializer', service(ReportingRuntimeInitializer::class));
    $services->get(SerializedRuntimeInitializer::class)
        ->arg('$initializer', service(ObservedRuntimeInitializer::class))
        ->arg('$mutex', service(LocalKeyedMutex::class));
    $services->alias(RuntimeInitializerInterface::class, SerializedRuntimeInitializer::class);

    $registries = [
        CompletionProviderRegistry::class => 'lsp.provider.completion',
        CodeActionProviderRegistry::class => 'lsp.provider.code_action',
        HoverProviderRegistry::class => 'lsp.provider.hover',
        DiagnosticCollector::class => 'lsp.provider.diagnostic',
        DefinitionProviderRegistry::class => 'lsp.provider.definition',
        DocumentLinkProviderRegistry::class => 'lsp.provider.document_link',
        ReferencesProviderRegistry::class => 'lsp.provider.references',
        RenameProviderRegistry::class => 'lsp.provider.rename',
        SourceIndexProviderPipeline::class => 'lsp.source_index_provider',
    ];
    foreach ($registries as $registry => $tag) {
        $services->get($registry)->arg('$providers', tagged_iterator($tag));
    }
    $services->get(ApplicationSourceScanner::class)
        ->arg('$mutex', service(LocalKeyedMutex::class));
    $services->get(ProjectStateCleaner::class)
        ->arg('$states', tagged_iterator('lsp.project_state'));
    $services->get(RuntimeSnapshotLoaderRegistry::class)
        ->arg('$loaders', tagged_iterator('lsp.runtime_snapshot_loader'));

    foreach ([MessengerCodeLensProvider::class, EventCodeLensProvider::class, TwigComponentCodeLensProvider::class, StimulusCodeLensProvider::class, DoctrineRelationshipCodeLensProvider::class] as $priority => $provider) {
        $services->get($provider)->tag('lsp.provider.code_lens', ['priority' => -$priority]);
    }
    $services->get(CodeLensProviderRegistry::class)
        ->arg('$providers', tagged_iterator('lsp.provider.code_lens'));

    $services->get(CheckCommand::class)->public();
    $services->get(LanguageServer::class)->public();
};
