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
            '--sections=routes,container,twig,translations,configuration,environment,messenger,events',
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
        self::assertContains('kernel.request', array_column(\is_array($events['events'] ?? null) ? $events['events'] : [], 'name'));
        self::assertNotSame([], $events['listeners'] ?? []);
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
