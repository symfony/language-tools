<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\ProjectAssetSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationSnapshotLoader;
use Symfony\Lsp\Feature\Console\ConsoleIndexRegistry;
use Symfony\Lsp\Feature\Console\ProjectConsoleSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\ProjectDoctrineSnapshotLoader;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\ProjectEnvironmentSnapshotLoader;
use Symfony\Lsp\Feature\Event\EventIndexRegistry;
use Symfony\Lsp\Feature\Event\ProjectEventSnapshotLoader;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\ProjectMessengerSnapshotLoader;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\ProjectMetadataSnapshotLoader;
use Symfony\Lsp\Feature\Route\ProjectRouteSnapshotLoader;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Security\ProjectSecuritySnapshotLoader;
use Symfony\Lsp\Feature\Security\SecurityIndexRegistry;
use Symfony\Lsp\Feature\Stimulus\ProjectStimulusSnapshotLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Feature\Translation\ProjectTranslationSnapshotLoader;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\ProjectTwigComponentSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Tests\Support\SnapshotSections;

final class RuntimeSnapshotLoaderContractTest extends TestCase
{
    /**
     * A section the bridge returns is the whole truth about its subsystem, so
     * every loader replaces what it loaded, and a key the section does not
     * carry is an empty set rather than a reason to keep a stale index.
     *
     * @param array<array-key, mixed> $section
     * @param callable(Project): bool $loaded
     */
    #[DataProvider('sections')]
    public function testASectionWithoutItsKeysEmptiesWhatItLoaded(RuntimeSnapshotLoaderInterface $loader, array $section, callable $loaded): void
    {
        $project = new Project('/workspace', 'file:///workspace');

        $loader->load($project, SnapshotSections::of($project, $section));
        self::assertTrue($loaded($project), 'The fixture section was not loaded.');

        $loader->load($project, SnapshotSections::of($project, ['complete' => true]));

        self::assertFalse($loaded($project));
    }

    public function testCoversEveryRuntimeSnapshotLoader(): void
    {
        $sections = [];
        foreach (self::sections() as $case) {
            $sections[] = $case[0]->section();
        }
        sort($sections);

        $loaders = [];
        foreach ((new Finder())->files()->name('Project*SnapshotLoader.php')->in(\dirname(__DIR__, 2).'/src/Feature') as $file) {
            $class = 'Symfony\\Lsp\\Feature\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            if (is_a($class, RuntimeSnapshotLoaderInterface::class, true)) {
                $loaders[] = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->section();
            }
        }
        sort($loaders);

        self::assertSame($loaders, $sections);
    }

    /** @return iterable<string, array{RuntimeSnapshotLoaderInterface, array<array-key, mixed>, callable(Project): bool}> */
    public static function sections(): iterable
    {
        $assets = new AssetIndexRegistry();
        yield 'assets' => [
            new ProjectAssetSnapshotLoader($assets),
            ['assets' => [['logicalPath' => 'app.js', 'sourcePath' => '/workspace/assets/app.js']]],
            static fn (Project $project): bool => null !== $assets->forProject($project)->asset('app.js'),
        ];

        $configuration = new ConfigurationIndexRegistry();
        yield 'configuration' => [
            new ProjectConfigurationSnapshotLoader($configuration),
            ['bundles' => [['alias' => 'framework', 'tree' => ['name' => 'framework']]]],
            static fn (Project $project): bool => [] !== $configuration->forProject($project)->roots(),
        ];

        $console = new ConsoleIndexRegistry();
        yield 'console' => [
            new ProjectConsoleSnapshotLoader($console),
            ['commands' => [['class' => 'App\Command\ReportCommand']]],
            static fn (Project $project): bool => null !== $console->forProject($project)->command('App\Command\ReportCommand'),
        ];

        $services = new ServiceIndexRegistry();
        $parameters = new ParameterIndexRegistry();
        yield 'container' => [
            new ProjectServiceSnapshotLoader($services, $parameters),
            ['items' => [['id' => 'app.mailer']], 'parameters' => [['name' => 'app.storage_dir']]],
            static fn (Project $project): bool => null !== $services->forProject($project)->get('app.mailer')
                && null !== $parameters->forProject($project)->get('app.storage_dir'),
        ];

        $doctrine = new DoctrineIndexRegistry();
        yield 'doctrine' => [
            new ProjectDoctrineSnapshotLoader($doctrine, new UriToPathConverter()),
            ['entities' => [['className' => 'App\Entity\Book', 'file' => '/workspace/src/Entity/Book.php']]],
            static fn (Project $project): bool => null !== $doctrine->forProject($project)->entity('App\Entity\Book'),
        ];

        $environment = new EnvironmentIndexRegistry();
        yield 'environment' => [
            new ProjectEnvironmentSnapshotLoader($environment),
            ['processors' => [['name' => 'json', 'type' => 'array']]],
            static fn (Project $project): bool => [] !== $environment->forProject($project)->processors(),
        ];

        $events = new EventIndexRegistry();
        yield 'events' => [
            new ProjectEventSnapshotLoader($events),
            ['events' => [['name' => 'order.shipped']]],
            static fn (Project $project): bool => null !== $events->forProject($project)->event('order.shipped'),
        ];

        $messenger = new MessengerIndexRegistry();
        yield 'messenger' => [
            new ProjectMessengerSnapshotLoader($messenger),
            ['buses' => [['name' => 'command.bus']]],
            static fn (Project $project): bool => null !== $messenger->forProject($project)->bus('command.bus'),
        ];

        $metadata = new MetadataIndexRegistry();
        yield 'metadata' => [
            new ProjectMetadataSnapshotLoader($metadata),
            ['forms' => [['class' => 'App\Form\UserType']], 'constraints' => [['name' => 'Alpha', 'class' => 'App\Validator\Alpha']]],
            static fn (Project $project): bool => null !== $metadata->forProject($project)->formType('App\Form\UserType')
                && null !== $metadata->forProject($project)->constraint('Alpha'),
        ];

        $routes = new RouteIndexRegistry();
        yield 'routes' => [
            new ProjectRouteSnapshotLoader($routes),
            ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
            static fn (Project $project): bool => null !== $routes->forProject($project)->get('homepage'),
        ];

        $security = new SecurityIndexRegistry();
        yield 'security' => [
            new ProjectSecuritySnapshotLoader($security),
            ['firewalls' => [['name' => 'main']]],
            static fn (Project $project): bool => null !== $security->forProject($project)->firewall('main'),
        ];

        $stimulus = new StimulusIndexRegistry();
        yield 'stimulus' => [
            new ProjectStimulusSnapshotLoader($stimulus, new StimulusControllerSourceLoader(new JavaScriptTokenizer(), new StimulusControllerSourceAnalyzer(new PositionConverter()))),
            ['controllers' => [['name' => 'search', 'sourcePath' => '/workspace/assets/controllers/search_controller.js']]],
            static fn (Project $project): bool => null !== $stimulus->forProject($project)->controller('search'),
        ];

        $translations = new TranslationIndexRegistry();
        yield 'translations' => [
            new ProjectTranslationSnapshotLoader($translations),
            ['items' => [['key' => 'article.title', 'domain' => 'messages', 'locale' => 'en', 'message' => 'Article']]],
            static fn (Project $project): bool => [] !== $translations->forProject($project)->messages('messages', 'article.title'),
        ];

        $templates = new TemplateIndexRegistry(new DependencyInjectionSourceIndexRegistry());
        yield 'twig' => [
            new ProjectTemplateSnapshotLoader($templates, new UriToPathConverter()),
            ['globals' => ['app']],
            static fn (Project $project): bool => $templates->forProject($project)->isGlobal('app'),
        ];

        $components = new TwigComponentIndexRegistry();
        yield 'twig_components' => [
            new ProjectTwigComponentSnapshotLoader($components, new UriToPathConverter()),
            ['enabled' => true, 'names' => ['Alert']],
            static fn (Project $project): bool => $components->forProject($project)->hasRuntimeName('Alert'),
        ];
    }
}
