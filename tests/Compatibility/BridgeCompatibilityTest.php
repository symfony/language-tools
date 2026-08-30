<?php

namespace Symfony\Lsp\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeCompatibilityTest extends TestCase
{
    public function testRealSymfonyApplicationSnapshot(): void
    {
        $project = getenv('SYMFONY_LSP_COMPAT_PROJECT');
        if (false === $project || !is_file($project.'/vendor/autoload.php')) {
            self::markTestSkipped('The real Symfony compatibility fixture is not installed.');
        }

        $process = (new NativeProcessRunner(30.0))->run([
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=routes,container,twig,twig_components,translations,configuration,environment,messenger,events,security,assets,stimulus,console',
            '--rebuild-container=1',
        ], $project);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['project'] ?? null);
        $expectedBranch = getenv('SYMFONY_LSP_COMPAT_BRANCH');
        if (false !== $expectedBranch) {
            self::assertSame(rtrim($expectedBranch, '.*'), $result['project']['symfonyBranch'] ?? null);
        }
        self::assertSame(['status' => 'valid'], $result['configurationValidation'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        $routes = $this->section($result['sections'], 'routes');
        $container = $this->section($result['sections'], 'container');
        $translations = $this->section($result['sections'], 'translations');
        $configuration = $this->section($result['sections'], 'configuration');
        $environment = $this->section($result['sections'], 'environment');
        $twig = $this->section($result['sections'], 'twig');
        $messenger = $this->section($result['sections'], 'messenger');
        $events = $this->section($result['sections'], 'events');
        $security = $this->section($result['sections'], 'security');
        $assets = $this->section($result['sections'], 'assets');
        $stimulus = $this->section($result['sections'], 'stimulus');
        $console = $this->section($result['sections'], 'console');

        $routeItems = \is_array($routes['items'] ?? null) ? $routes['items'] : [];
        self::assertContains('fixture_home', array_column($routeItems, 'name'));
        self::assertContains('fixture_health', array_column($routeItems, 'name'));
        $routeResources = \is_array($routes['resources'] ?? null) ? $routes['resources'] : [];
        self::assertContains('config/routes.yaml', $routeResources);
        self::assertContains('config/http_endpoints.yaml', $routeResources);
        $localizedRoutes = array_values(array_filter(
            $routeItems,
            static fn (mixed $route): bool => \is_array($route)
                && \is_string($route['name'] ?? null)
                && str_starts_with($route['name'], 'fixture_localized.'),
        ));
        self::assertSame(['fixture_localized', 'fixture_localized'], array_column($localizedRoutes, 'canonical'));
        self::assertContains('App\\Environment\\CustomEnvVarProcessor', array_column(\is_array($container['items'] ?? null) ? $container['items'] : [], 'class'));
        self::assertContains('fixture.message', array_column(\is_array($translations['items'] ?? null) ? $translations['items'] : [], 'key'));
        $configurationBundles = \is_array($configuration['bundles'] ?? null) ? $configuration['bundles'] : [];
        self::assertContains('framework', array_column($configurationBundles, 'alias'));
        $frameworkTrees = array_values(array_filter($configurationBundles, static fn (mixed $bundle): bool => \is_array($bundle) && 'framework' === ($bundle['alias'] ?? null)));
        self::assertCount(1, $frameworkTrees);
        $frameworkTree = $frameworkTrees[0]['tree'] ?? null;
        self::assertIsArray($frameworkTree);
        $frameworkChildren = array_column(\is_array($frameworkTree['children'] ?? null) ? $frameworkTree['children'] : [], null, 'name');
        self::assertIsArray($frameworkChildren['cache'] ?? null);
        $cacheChildren = array_column(\is_array($frameworkChildren['cache']['children'] ?? null) ? $frameworkChildren['cache']['children'] : [], null, 'name');
        $cachePools = $cacheChildren['pools'] ?? null;
        self::assertIsArray($cachePools);
        self::assertSame('name', $cachePools['keyAttribute'] ?? null);
        $cachePoolPrototype = $cachePools['prototype'] ?? null;
        self::assertIsArray($cachePoolPrototype);
        self::assertSame(['adapter' => 'adapters'], $cachePoolPrototype['aliases'] ?? null);
        $shorthandTrees = array_values(array_filter($configurationBundles, static fn (mixed $bundle): bool => \is_array($bundle) && 'fixture_shorthand' === ($bundle['alias'] ?? null)));
        self::assertCount(1, $shorthandTrees);
        $shorthandTree = $shorthandTrees[0]['tree'] ?? null;
        self::assertIsArray($shorthandTree);
        $shorthandChildren = [];
        foreach (\is_array($shorthandTree['children'] ?? null) ? $shorthandTree['children'] : [] as $child) {
            if (\is_array($child) && \is_string($child['name'] ?? null)) {
                $shorthandChildren[$child['name']] = $child;
            }
        }
        // shorthand keys relocated into the pools prototype must be merged as regular children
        $storageChildren = array_column(\is_array($shorthandChildren['storage']['children'] ?? null) ? $shorthandChildren['storage']['children'] : [], null, 'name');
        $storageNames = array_keys($storageChildren);
        foreach (['default_pool', 'pools', 'dsn', 'size', 'mode'] as $expectedChild) {
            self::assertContains($expectedChild, $storageNames);
        }
        $storagePools = $storageChildren['pools'] ?? null;
        self::assertIsArray($storagePools);
        self::assertSame('name', $storagePools['keyAttribute'] ?? null);
        self::assertSame(
            ['null' => true, 'true' => true, 'false' => true, 'scalar' => false, 'unknownKeys' => false],
            $shorthandChildren['feature']['accepts'] ?? null,
        );
        self::assertTrue($shorthandChildren['storage']['normalizeKeys'] ?? false);
        self::assertFalse($shorthandChildren['exact_keys']['normalizeKeys'] ?? true);
        self::assertIsArray($shorthandChildren['exact_keys']['children'] ?? null);
        self::assertSame(
            ['default-src', 'report-uri'],
            array_column($shorthandChildren['exact_keys']['children'], 'name'),
        );
        $configurationResources = $configuration['resources'] ?? null;
        self::assertIsArray($configurationResources);
        self::assertContains(realpath($project.'/config/services.yaml'), $configurationResources);
        self::assertContains('fixture_upper', array_column(\is_array($environment['processors'] ?? null) ? $environment['processors'] : [], 'name'));
        self::assertNotSame([], $twig['paths'] ?? []);
        self::assertContains('command.bus', array_column(\is_array($messenger['buses'] ?? null) ? $messenger['buses'] : [], 'name'));
        self::assertContains('async', array_column(\is_array($messenger['transports'] ?? null) ? $messenger['transports'] : [], 'name'));
        self::assertContains('App\\Message\\Ping', array_column(\is_array($messenger['messages'] ?? null) ? $messenger['messages'] : [], 'class'));
        self::assertContains('App\\MessageHandler\\PingHandler', array_column(\is_array($messenger['handlers'] ?? null) ? $messenger['handlers'] : [], 'class'));
        $eventItems = \is_array($events['events'] ?? null) ? $events['events'] : [];
        $eventListeners = \is_array($events['listeners'] ?? null) ? $events['listeners'] : [];
        self::assertContains('App\\Event\\OrderPlaced', array_column($eventItems, 'name'));
        self::assertContains('App\\EventListener\\NotifyCustomer', array_column($eventListeners, 'class'));
        // the listener constructor throws, so metadata must load without instantiating it
        self::assertContains('legacy.order_placed', array_column($eventItems, 'name'));
        self::assertContains(
            ['event' => 'legacy.order_placed', 'class' => 'App\\EventListener\\AuditOrder', 'method' => '__invoke', 'priority' => 0],
            $eventListeners,
        );
        self::assertContains('main', array_column(\is_array($security['firewalls'] ?? null) ? $security['firewalls'] : [], 'name'));
        self::assertContains('fixture_users', array_column(\is_array($security['providers'] ?? null) ? $security['providers'] : [], 'name'));
        self::assertContains('ROLE_ADMIN', array_column(\is_array($security['roles'] ?? null) ? $security['roles'] : [], 'name'));
        self::assertContains('App\\Security\\PostVoter', array_column(\is_array($security['voters'] ?? null) ? $security['voters'] : [], 'class'));
        $mappedAssets = \is_array($assets['assets'] ?? null) ? $assets['assets'] : [];
        self::assertContains('app.js', array_column($mappedAssets, 'logicalPath'));
        $applicationAssets = array_values(array_filter($mappedAssets, static fn (mixed $asset): bool => \is_array($asset) && 'app.js' === ($asset['logicalPath'] ?? null)));
        self::assertCount(1, $applicationAssets);
        self::assertFalse($applicationAssets[0]['vendor'] ?? true);
        self::assertContains('app', array_column(\is_array($assets['importMap'] ?? null) ? $assets['importMap'] : [], 'name'));
        $stimulusControllers = \is_array($stimulus['controllers'] ?? null) ? $stimulus['controllers'] : [];
        self::assertContains('search', array_column($stimulusControllers, 'name'));
        self::assertIsArray($stimulusControllers[0] ?? null);
        $stimulusActions = $stimulusControllers[0]['actions'] ?? [];
        $stimulusTargets = $stimulusControllers[0]['targets'] ?? [];
        self::assertIsArray($stimulusActions);
        self::assertIsArray($stimulusTargets);
        self::assertContains('open', $stimulusActions);
        self::assertContains('results', $stimulusTargets);
        $consoleCommands = \is_array($console['commands'] ?? null) ? $console['commands'] : [];
        $fixtureCommands = array_values(array_filter($consoleCommands, static fn (mixed $command): bool => \is_array($command) && 'App\\Command\\FixtureCommand' === ($command['class'] ?? null)));
        self::assertCount(1, $fixtureCommands);
        $fixtureArguments = $fixtureCommands[0]['arguments'] ?? null;
        $fixtureOptions = $fixtureCommands[0]['options'] ?? null;
        self::assertIsArray($fixtureArguments);
        self::assertIsArray($fixtureOptions);
        self::assertContains('message', $fixtureArguments);
        foreach (['env', 'format', 'help', 'no-debug', 'verbose'] as $option) {
            self::assertContains($option, $fixtureOptions);
        }
        self::assertTrue($fixtureCommands[0]['complete'] ?? false);
    }

    public function testRealInvalidBundleConfiguration(): void
    {
        $project = getenv('SYMFONY_LSP_COMPAT_PROJECT');
        if (false === $project || !is_file($project.'/vendor/autoload.php')) {
            self::markTestSkipped('The real Symfony compatibility fixture is not installed.');
        }

        $configurationFile = $project.'/config/packages/symfony_lsp_invalid.yaml';
        file_put_contents($configurationFile, <<<'YAML'
            framework:
                canary_invalid_option: CANARY_SECRET_CONFIGURATION_VALUE
            YAML);
        try {
            $process = (new NativeProcessRunner(30.0))->run([
                \PHP_BINARY,
                \dirname(__DIR__, 2).'/resources/bridge.php',
                '--project='.$project,
                '--environment=test',
                '--debug=1',
                '--rebuild-container=1',
            ], $project);
        } finally {
            @unlink($configurationFile);
        }

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_CONFIGURATION_VALUE', $snapshot);
        self::assertStringNotContainsString('Unrecognized option', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([
            'status' => 'invalid',
            'kind' => 'configuration',
            'path' => 'framework',
        ], $result['configurationValidation'] ?? null);
        self::assertSame([], $result['sections'] ?? null);
        self::assertSame([], $result['errors'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $sections
     *
     * @return array<array-key, mixed>
     */
    private function section(array $sections, string $name): array
    {
        self::assertIsArray($sections[$name] ?? null);

        return $sections[$name];
    }
}
