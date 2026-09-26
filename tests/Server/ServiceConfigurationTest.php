<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
use Symfony\Lsp\Feature\Translation\TranslationParameterAnalyzer;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFactsStore;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;
use Symfony\Lsp\Parser\Yaml\YamlScalarDecoder;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeBridgeTimingNormalizer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;
use Symfony\Lsp\Runtime\SnapshotSection;
use Symfony\Lsp\Server\ContainerFactory;

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
            SnapshotSection::class,
        ] as $class) {
            self::assertFalse($container->hasDefinition($class), \sprintf('The manually constructed class "%s" is registered as a service.', $class));
        }

        self::assertTrue(
            $container->getDefinition(ProjectAnalysisSettings::class)->hasTag('container.excluded'),
            \sprintf('The value object "%s" is not excluded from service discovery.', ProjectAnalysisSettings::class),
        );

        foreach ([
            PersistentSourceIndexStore::class,
            TranslationParameterAnalyzer::class,
            TreeSitterResultDecoder::class,
            YamlScalarDecoder::class,
            RuntimeBridgeTimingNormalizer::class,
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
            GitignoreMatcher::class,
            PersistentSourceIndexStore::class,
            ProjectIndexStatusRegistry::class,
            PublicAssetResolver::class,
            AnalysisSettingsRegistry::class,
            SourceOverlayHealthRegistry::class,
            RuntimeSnapshotState::class,
            WorkspaceTrust::class,
            WorkspaceTrustManager::class,
        ] as $service) {
            self::assertContains($service, $tagged, \sprintf('The project state holder "%s" is not released on project removal.', $service));
        }

        foreach ($tagged as $id) {
            self::assertTrue(is_subclass_of($id, ProjectStateInterface::class), \sprintf('The tagged service "%s" does not implement the project state contract.', $id));
        }
    }

    public function testLoadsEverySectionTheBridgeProduces(): void
    {
        $container = $this->container();
        $container->compile();
        $loaders = [];
        foreach (array_keys($container->findTaggedServiceIds('lsp.runtime_snapshot_loader')) as $id) {
            if (!class_exists($id)) {
                continue;
            }
            $loader = (new \ReflectionClass($id))->newInstanceWithoutConstructor();
            self::assertInstanceOf(RuntimeSnapshotLoaderInterface::class, $loader);
            $loaders[] = $loader;
        }
        $sections = (new RuntimeSnapshotLoaderRegistry($loaders, new ContainerPathMapper(new RuntimeConfiguration())))->sections();
        sort($sections);
        $bridgeSections = $this->bridgeSections();
        sort($bridgeSections);

        self::assertSame($bridgeSections, $sections);
    }

    /** @return list<string> */
    private function bridgeSections(): array
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/resources/bridge.php');
        self::assertIsString($source);
        preg_match_all('/\'([a-z_]+)\' => symfonyLspBridge\w+Section\(\$context\)/', $source, $matches);
        self::assertNotEmpty($matches[1], 'The bridge section dispatch table was not found.');

        return $matches[1];
    }

    private function container(): ContainerBuilder
    {
        return (new ContainerFactory())->create('test');
    }
}
