<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\TwigFixtureBuilder;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class BridgeTwigCallablesTest extends TestCase
{
    public function testResolvesEveryTwigCallableToTheMethodItCalls(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);

        $process = (new NativeProcessRunner(30.0))->run([
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=twig',
        ], $project);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $snapshot = json_decode($process->stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame([], $snapshot['errors'] ?? null, $process->stdout);
        self::assertIsArray($snapshot['sections'] ?? null);
        $twig = $snapshot['sections']['twig'] ?? null;
        self::assertIsArray($twig);
        self::assertSame([], $twig['warnings'] ?? null);
        self::assertNotSame([], $twig['paths'] ?? []);
        $callables = $twig['callables'] ?? null;
        self::assertIsArray($callables);
        $byName = [];
        foreach ($callables as $callable) {
            self::assertIsArray($callable);
            self::assertIsString($callable['kind'] ?? null);
            self::assertIsString($callable['name'] ?? null);
            foreach ($callable as $value) {
                self::assertTrue(\is_string($value) || \is_bool($value), 'A Twig callable exposes a value that is neither a name, a path nor a flag.');
            }
            $byName[$callable['kind'].':'.$callable['name']] = $callable;
        }
        $sorted = array_keys($byName);
        sort($sorted);
        self::assertSame($sorted, array_keys($byName));

        $src = $project.'/src/Twig/';
        $options = ['needsCharset' => false, 'needsEnvironment' => false, 'needsContext' => false, 'needsIsSandboxed' => false, 'variadic' => false];
        self::assertSame([
            'kind' => 'function',
            'name' => 'fixture_runtime',
            'class' => 'App\\Twig\\FixtureRuntime',
            'method' => 'format',
            'declaringClass' => 'App\\Twig\\FixtureRuntime',
            'file' => $src.'FixtureRuntime.php',
            ...$options,
            'needsContext' => true,
        ], $byName['function:fixture_runtime'] ?? null);
        self::assertSame([
            'kind' => 'function',
            'name' => 'fixture_inherited',
            'class' => 'App\\Twig\\FixtureRuntime',
            'method' => 'label',
            'declaringClass' => 'App\\Twig\\AbstractFixtureRuntime',
            'file' => $src.'AbstractFixtureRuntime.php',
            ...$options,
            'needsEnvironment' => true,
        ], $byName['function:fixture_inherited'] ?? null);
        self::assertSame([
            'kind' => 'function',
            'name' => 'fixture_static',
            'class' => 'App\\Twig\\FixtureExtension',
            'method' => 'staticFunction',
            'declaringClass' => 'App\\Twig\\FixtureExtension',
            'file' => $src.'FixtureExtension.php',
            ...$options,
        ], $byName['function:fixture_static'] ?? null);
        self::assertSame([
            'kind' => 'function',
            'name' => 'fixture_invokable',
            'class' => 'App\\Twig\\FixtureInvokable',
            'method' => '__invoke',
            'declaringClass' => 'App\\Twig\\FixtureInvokable',
            'file' => $src.'FixtureInvokable.php',
            ...$options,
        ], $byName['function:fixture_invokable'] ?? null);
        self::assertSame([
            'kind' => 'filter',
            'name' => 'fixture_object',
            'class' => 'App\\Twig\\FixtureExtension',
            'method' => 'objectFilter',
            'declaringClass' => 'App\\Twig\\FixtureExtension',
            'file' => $src.'FixtureExtension.php',
            ...$options,
            'variadic' => true,
        ], $byName['filter:fixture_object'] ?? null);
        self::assertSame([
            'kind' => 'filter',
            'name' => 'fixture_first_class',
            'class' => 'App\\Twig\\FixtureExtension',
            'method' => 'firstClass',
            'declaringClass' => 'App\\Twig\\FixtureExtension',
            'file' => $src.'FixtureExtension.php',
            ...$options,
            'needsCharset' => true,
        ], $byName['filter:fixture_first_class'] ?? null);
        self::assertSame([
            'kind' => 'filter',
            'name' => 'fixture_trait',
            'class' => 'App\\Twig\\FixtureRuntime',
            'method' => 'shout',
            'declaringClass' => 'App\\Twig\\FixtureFormattingTrait',
            'file' => $src.'FixtureFormattingTrait.php',
            ...$options,
        ], $byName['filter:fixture_trait'] ?? null);

        foreach (['function:fixture_closure', 'function:fixture_dynamic_*', 'filter:fixture_global', 'filter:fixture_missing'] as $skipped) {
            self::assertArrayNotHasKey($skipped, $byName);
        }

        $component = $byName['function:component'] ?? null;
        self::assertIsArray($component);
        self::assertSame('Symfony\\UX\\TwigComponent\\Twig\\ComponentRuntime', $component['class'] ?? null);
        self::assertSame('render', $component['method'] ?? null);
        self::assertSame($project.'/vendor/symfony/ux-twig-component/src/Twig/ComponentRuntime.php', $component['file'] ?? null);
        $path = $byName['function:path'] ?? null;
        self::assertIsArray($path);
        self::assertSame('Symfony\\Bridge\\Twig\\Extension\\RoutingExtension', $path['class'] ?? null);
        self::assertSame('getPath', $path['method'] ?? null);
        self::assertSame($project.'/vendor/symfony/twig-bridge/Extension/RoutingExtension.php', $path['file'] ?? null);
    }

    public function testSkipsACallableWhoseClassCannotBeLoaded(): void
    {
        $workspace = new TestWorkspace();
        try {
            (new TwigFixtureBuilder($workspace))->writeTwigApplicationWithAnUnloadableCallableClass();

            $process = (new BridgeProcessFixture($workspace->rootPath))->run(['--sections=twig']);

            self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
            $result = $process->snapshot;
            self::assertIsArray($result);
            self::assertSame([], $result['errors'] ?? null, $process->stdout);
            self::assertIsArray($result['sections'] ?? null);
            $twig = $result['sections']['twig'] ?? null;
            self::assertIsArray($twig);
            self::assertSame([], $twig['warnings'] ?? null);
            $callables = $twig['callables'] ?? null;
            self::assertIsArray($callables);
            self::assertSame(['working'], array_column($callables, 'name'));
            self::assertSame(['App\\Twig\\WorkingRuntime'], array_column($callables, 'class'));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testKeepsLoaderPathsAndGlobalsWhenTwigCallablesCannotBeListed(): void
    {
        $workspace = new TestWorkspace();
        try {
            (new TwigFixtureBuilder($workspace))->writeTwigApplicationWithFailingCallables();

            $process = (new BridgeProcessFixture($workspace->rootPath))->run(['--sections=twig']);

            self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
            self::assertStringNotContainsString('CANARY_SECRET_', $process->stdout);
            $result = $process->snapshot;
            self::assertIsArray($result);
            self::assertSame([], $result['errors'] ?? null, $process->stdout);
            self::assertIsArray($result['sections'] ?? null);
            self::assertSame([
                'complete' => true,
                'paths' => [['namespace' => '(None)', 'path' => '/templates']],
                'globals' => ['app'],
                'callables' => [],
                'warnings' => ['The Twig callables could not be listed.'],
            ], $result['sections']['twig'] ?? null);
        } finally {
            $workspace->cleanup();
        }
    }
}
