<?php

use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Microsoft\PhpParser\Parser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Client\JsonRpcClient;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineProvider;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\Event\EventProvider;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerHandler;
use Symfony\Lsp\Feature\Messenger\MessengerProvider;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Feature\Route\RouteCompletionProvider;
use Symfony\Lsp\Feature\Route\RouteSnapshotLoader;
use Symfony\Lsp\Feature\Security\SecurityUserProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusProvider;
use Symfony\Lsp\Feature\Twig\LiveComponentEventProvider;
use Symfony\Lsp\Feature\Twig\TemplateCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentProvider;
use Symfony\Lsp\Feature\Twig\TwigVariableProvider;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexStoreInterface;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Progress\ProgressReporterInterface;
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
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;
use Symfony\Lsp\Server\LanguageServer;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Server\WorkDoneProgressReporter;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$serverVersion', param('server.version'))
        ->bind('$version', param('server.version'))
        ->bind('$bridgeSource', param('bridge.source'))
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
    ];
    foreach ($providerTags as $interface => $tag) {
        $services->instanceof($interface)->tag($tag);
    }

    $services->load('Symfony\\Lsp\\Feature\\', '../src/Feature/*Registry.php');
    $featureGroups = [
        'Route' => [],
        'DependencyInjection' => [],
        'Twig' => [
            TemplateCompletionHandler::class,
            TemplateNavigationProvider::class,
            TemplateCodeActionProvider::class,
            TwigVariableProvider::class,
            TwigComponentProvider::class,
            LiveComponentEventProvider::class,
        ],
        'Translation' => [],
        'Environment' => [],
        'Configuration' => [],
        'Messenger' => [],
        'Event' => [],
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
            '../src/Feature/'.$feature.'/*{Provider,Handler,Extractor,Indexer,Registry,Resolver,Loader,Publisher,Parser}.php',
        );
    }
    foreach ([MessengerHandler::class, RouteCompletionProvider::class, RouteSnapshotLoader::class, SecurityUserProvider::class] as $class) {
        $services->remove($class);
    }

    $services->load('Symfony\\Lsp\\Client\\', '../src/Client/*Client.php');
    $services->load('Symfony\\Lsp\\Document\\', '../src/Document/*{Resolver,Store,Synchronizer,Converter}.php');
    $services->load('Symfony\\Lsp\\Index\\', '../src/Index/*{Scanner,Handler,Store,Registry,Codec,Hasher}.php');
    $services->load('Symfony\\Lsp\\Parser\\', '../src/Parser/**/*{Parser,Decoder}.php');
    $services->load('Symfony\\Lsp\\Project\\', '../src/Project/*{Discovery,Registry,Resolver,Settings,Converter,Configuration,Trust,Manager}.php');
    $services->load('Symfony\\Lsp\\Runtime\\', '../src/Runtime/*{Installer,Runner,Initializer,Refresher,Scheduler,Configuration,Registry,Planner}.php');
    $services->load('Symfony\\Lsp\\Server\\', '../src/Server/*{Server,Logger,State,Reporter}.php');

    $services->set(Parser::class);
    $services->set(Filesystem::class);
    $services->set(JsonRpcPeer::class)->synthetic()->public();
    $services->set(JsonRpcDispatcher::class)->synthetic()->public();
    $services->set(ServerLogger::class)->synthetic()->public();
    $services->set(TreeSitterParserInterface::class)->synthetic()->public();

    $services->alias(ClientInterface::class, JsonRpcClient::class);
    $services->alias(PhpParserInterface::class, TolerantPhpParser::class);
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
    $services->alias(RuntimeInitializerInterface::class, ObservedRuntimeInitializer::class);

    $registries = [
        CompletionProviderRegistry::class => 'lsp.provider.completion',
        CodeActionProviderRegistry::class => 'lsp.provider.code_action',
        HoverProviderRegistry::class => 'lsp.provider.hover',
        DiagnosticProviderRegistry::class => 'lsp.provider.diagnostic',
        DefinitionProviderRegistry::class => 'lsp.provider.definition',
        DocumentLinkProviderRegistry::class => 'lsp.provider.document_link',
        ReferencesProviderRegistry::class => 'lsp.provider.references',
        RenameProviderRegistry::class => 'lsp.provider.rename',
        ApplicationSourceScanner::class => 'lsp.source_index_provider',
    ];
    foreach ($registries as $registry => $tag) {
        $services->get($registry)->arg('$providers', tagged_iterator($tag));
    }
    $services->get(RuntimeSnapshotLoaderRegistry::class)
        ->arg('$loaders', tagged_iterator('lsp.runtime_snapshot_loader'));

    foreach ([MessengerProvider::class, EventProvider::class, TwigComponentProvider::class, StimulusProvider::class, DoctrineProvider::class] as $priority => $provider) {
        $services->get($provider)->tag('lsp.provider.code_lens', ['priority' => -$priority]);
    }
    $services->get(CodeLensProviderRegistry::class)
        ->arg('$providers', tagged_iterator('lsp.provider.code_lens'));

    $services->get(LanguageServer::class)->public();
};
