<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Feature\Asset\PublicAssetResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineCompletionProvider;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\Messenger\MessengerHandlerDeclaration;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Feature\Route\RouteSnapshotImporter;
use Symfony\Lsp\Feature\Security\SecurityUserProviderDeclaration;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Feature\Translation\TranslationParameterAnalyzer;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFactsStore;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;
use Symfony\Lsp\Parser\Yaml\YamlScalarDecoder;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;

final class ServiceConfigurationTest extends TestCase
{
    private const EXTENSION_POINTS = [
        CodeActionProviderInterface::class,
        CodeLensProviderInterface::class,
        CompletionProviderInterface::class,
        DefinitionProviderInterface::class,
        DiagnosticProviderInterface::class,
        DocumentLinkProviderInterface::class,
        HoverProviderInterface::class,
        ReferencesProviderInterface::class,
        RenameProviderInterface::class,
        SourceIndexProviderInterface::class,
        RuntimeSnapshotLoaderInterface::class,
    ];

    public function testRegistersEveryFeatureExtensionPoint(): void
    {
        $container = $this->container();

        $files = (new Finder())->files()->name('*.php')->in(\dirname(__DIR__, 2).'/src/Feature');
        foreach ($files as $file) {
            $class = 'Symfony\\Lsp\\Feature\\'.str_replace(['/', '\\'], '\\', substr($file->getRelativePathname(), 0, -4));
            if (!class_exists($class)) {
                continue;
            }
            foreach (self::EXTENSION_POINTS as $interface) {
                if (is_subclass_of($class, $interface)) {
                    self::assertTrue($container->hasDefinition($class), \sprintf('The feature extension point "%s" is not registered.', $class));
                    break;
                }
            }
        }
    }

    public function testRegistersOnlyContainerManagedServicesFromBroadDiscoveryGroups(): void
    {
        $container = $this->container();

        foreach ([
            MessengerHandlerDeclaration::class,
            RouteSnapshotImporter::class,
            SecurityUserProviderDeclaration::class,
            SourceFactsStore::class,
            PhpStringLiteralDecoder::class,
            TwigStringDecoder::class,
        ] as $class) {
            self::assertFalse($container->hasDefinition($class), \sprintf('The manually constructed class "%s" is registered as a service.', $class));
        }

        foreach ([
            PersistentSourceIndexStore::class,
            TranslationParameterAnalyzer::class,
            TreeSitterResultDecoder::class,
            YamlScalarDecoder::class,
        ] as $class) {
            self::assertTrue($container->hasDefinition($class), \sprintf('The injected collaborator "%s" is not registered as a service.', $class));
        }
    }

    public function testRegistersOneCompletionProviderPerMetadataDomain(): void
    {
        $container = $this->container();
        $container->compile();
        $providers = array_keys($container->findTaggedServiceIds('lsp.provider.completion'));
        $metadataProviders = array_values(array_filter($providers, static fn (string $provider): bool => str_starts_with($provider, 'Symfony\\Lsp\\Feature\\Metadata\\')));
        $doctrineProviders = array_values(array_filter($providers, static fn (string $provider): bool => str_starts_with($provider, 'Symfony\\Lsp\\Feature\\Doctrine\\')));

        self::assertSame([MetadataCompletionProvider::class], $metadataProviders);
        self::assertSame([DoctrineCompletionProvider::class], $doctrineProviders);
    }

    public function testEveryProjectStateServiceIsReleasedOnProjectRemoval(): void
    {
        $container = $this->container();
        $container->compile();
        $tagged = array_keys($container->findTaggedServiceIds('lsp.project_state'));

        foreach ([
            ApplicationSourceScanner::class,
            DebouncedRuntimeRefreshScheduler::class,
            DiagnosticProviderRegistry::class,
            PersistentSourceIndexStore::class,
            ProjectIndexStatusRegistry::class,
            PublicAssetResolver::class,
            RuntimeConfiguration::class,
            RuntimeSnapshotState::class,
            TranslationConfigurationRegistry::class,
            WorkspaceTrust::class,
            WorkspaceTrustManager::class,
        ] as $service) {
            self::assertContains($service, $tagged, \sprintf('The project state holder "%s" is not released on project removal.', $service));
        }

        foreach ($tagged as $id) {
            self::assertTrue(is_subclass_of($id, ProjectStateInterface::class), \sprintf('The tagged service "%s" does not implement the project state contract.', $id));
        }
    }

    private function container(): ContainerBuilder
    {
        $root = \dirname(__DIR__, 2);
        $container = new ContainerBuilder();
        $container->setParameter('server.version', 'test');
        $container->setParameter('bridge.source', $root.'/resources/bridge.php');
        (new PhpFileLoader($container, new FileLocator($root.'/resources')))->load('services.php');

        return $container;
    }
}
