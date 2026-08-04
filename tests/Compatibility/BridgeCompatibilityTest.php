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
            '--sections=routes,container,twig,translations,configuration,environment,messenger,events,security,assets,stimulus',
        ], $project);

        $snapshot = $process->stdout();
        self::assertSame(0, $process->exitCode(), $process->stderr()."\n".$snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['project'] ?? null);
        $expectedBranch = getenv('SYMFONY_LSP_COMPAT_BRANCH');
        if (false !== $expectedBranch) {
            self::assertSame(rtrim($expectedBranch, '.*'), $result['project']['symfonyBranch'] ?? null);
        }
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

        self::assertContains('fixture_home', array_column(\is_array($routes['items'] ?? null) ? $routes['items'] : [], 'name'));
        self::assertContains('App\\Environment\\CustomEnvVarProcessor', array_column(\is_array($container['items'] ?? null) ? $container['items'] : [], 'class'));
        self::assertContains('fixture.message', array_column(\is_array($translations['items'] ?? null) ? $translations['items'] : [], 'key'));
        self::assertContains('framework', array_column(\is_array($configuration['bundles'] ?? null) ? $configuration['bundles'] : [], 'alias'));
        self::assertContains('fixture_upper', array_column(\is_array($environment['processors'] ?? null) ? $environment['processors'] : [], 'name'));
        self::assertNotSame([], $twig['paths'] ?? []);
        self::assertContains('command.bus', array_column(\is_array($messenger['buses'] ?? null) ? $messenger['buses'] : [], 'name'));
        self::assertContains('async', array_column(\is_array($messenger['transports'] ?? null) ? $messenger['transports'] : [], 'name'));
        self::assertContains('App\\Message\\Ping', array_column(\is_array($messenger['messages'] ?? null) ? $messenger['messages'] : [], 'class'));
        self::assertContains('App\\MessageHandler\\PingHandler', array_column(\is_array($messenger['handlers'] ?? null) ? $messenger['handlers'] : [], 'class'));
        self::assertContains('App\\Event\\OrderPlaced', array_column(\is_array($events['events'] ?? null) ? $events['events'] : [], 'name'));
        self::assertContains('App\\EventListener\\NotifyCustomer', array_column(\is_array($events['listeners'] ?? null) ? $events['listeners'] : [], 'class'));
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
