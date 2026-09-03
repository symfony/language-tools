<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\AutoloaderFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\DoctrineFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\EnvironmentFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\EventFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\MetadataFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\RuntimeFrontControllerFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\SecurityFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\StimulusFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\TwigFixtureBuilder;

final class BridgeSectionsTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testExportsEnvironmentProcessorMetadataWithoutValues(): void
    {
        (new EnvironmentFixtureBuilder($this->workspace))->writeEnvironmentApplication();
        $previousSecret = getenv('APP_SECRET');
        putenv('APP_SECRET=CANARY_SECRET_ENVIRONMENT_VALUE');

        $process = $this->bridge->run(['--sections=environment']);
        if (false === $previousSecret) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET='.$previousSecret);
        }

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['environment'] ?? null);
        self::assertSame([
            ['name' => 'int', 'type' => 'int'],
            ['name' => 'json', 'type' => 'array'],
        ], $result['sections']['environment']['processors'] ?? null);
    }

    public function testNormalizesEventDispatcherMetadata(): void
    {
        (new EventFixtureBuilder($this->workspace))->writeEventApplication();

        $process = $this->bridge->run(['--sections=events']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['events'] ?? null);
        self::assertSame([
            ['name' => 'App\\Event\\OrderPlaced', 'class' => 'App\\Event\\OrderPlaced'],
            ['name' => 'legacy.order_placed', 'class' => null],
            ['name' => 'order.shipped', 'class' => null],
        ], $result['sections']['events']['events'] ?? null);
        self::assertSame([
            ['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10],
            ['event' => 'legacy.order_placed', 'class' => 'App\\EventListener\\AuditOrder', 'method' => '__invoke', 'priority' => 0],
            ['event' => 'order.shipped', 'class' => 'App\\EventSubscriber\\ShipmentSubscriber', 'method' => 'recordShipment', 'priority' => 5],
        ], $result['sections']['events']['listeners'] ?? null);
    }

    public function testResolvesTheKernelThroughARuntimeFrontController(): void
    {
        (new RuntimeFrontControllerFixtureBuilder($this->workspace))->writeRuntimeFrontControllerApplication();
        $previousOptions = getenv('APP_RUNTIME_OPTIONS');
        putenv('APP_RUNTIME_OPTIONS={"environment_option":"environment"}');

        $process = $this->bridge->run(['--sections=events']);
        if (false === $previousOptions) {
            putenv('APP_RUNTIME_OPTIONS');
        } else {
            putenv('APP_RUNTIME_OPTIONS='.$previousOptions);
        }

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['events'] ?? null);
        self::assertSame([
            ['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10],
        ], $result['sections']['events']['listeners'] ?? null);
    }

    public function testFallsBackToTheFrontControllerWhenTheConventionalKernelCannotBoot(): void
    {
        (new RuntimeFrontControllerFixtureBuilder($this->workspace))->writeBootstrapDependentKernelApplication();

        $process = $this->bridge->run(['--sections=events']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertSame(['status' => 'valid'], $result['configurationValidation'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['events'] ?? null);
        self::assertSame([
            ['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10],
        ], $result['sections']['events']['listeners'] ?? null);
        self::assertSame(['boot-1'], $this->cacheEntries());
    }

    public function testRebuildsTheContainerBeforeBootingAFrontControllerKernel(): void
    {
        (new RuntimeFrontControllerFixtureBuilder($this->workspace))->writeBootstrapDependentKernelApplication();
        $this->workspace->write('var/cache/marker', 'stale');

        $process = $this->bridge->run(['--sections=events', '--rebuild-container=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $process->stdout);
        self::assertSame(['boot-2'], $this->cacheEntries());
    }

    public function testReportsTheFrontControllerFailureWhenBothBootstrapsFail(): void
    {
        (new RuntimeFrontControllerFixtureBuilder($this->workspace))->writeBootstrapDependentKernelApplication(failingFrontController: true);

        $process = $this->bridge->run(['--sections=events,routes', '--error-details=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
        $errors = $this->errors($result);
        self::assertSame(['runtime', 'events', 'routes'], array_column($errors, 'section'));
        self::assertSame([true, false, false], array_map(static fn (array $error): bool => isset($error['cause']), $errors));
        self::assertSame('The application kernel could not be booted.', $errors[0]['message'] ?? null);
        self::assertSame(\RuntimeException::class, $this->kernelBootCause($errors)['class']);
        self::assertSame('CANARY_FRONT_CONTROLLER_FAILURE', $this->kernelBootCause($errors)['message']);
    }

    public function testReportsTheConventionalKernelFailureWithoutARuntimeFrontController(): void
    {
        (new RuntimeFrontControllerFixtureBuilder($this->workspace))->writeBootstrapDependentKernelApplication(frontController: false);

        $process = $this->bridge->run(['--sections=events', '--error-details=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        $errors = $this->errors($result);
        self::assertSame(['runtime', 'events'], array_column($errors, 'section'));
        self::assertSame(\Error::class, $this->kernelBootCause($errors)['class']);
        self::assertSame('Undefined constant "DISTRIBUTION_PROJECT_ROOT"', $this->kernelBootCause($errors)['message']);
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return list<array<array-key, mixed>>
     */
    private function errors(array $result): array
    {
        $errors = $result['errors'] ?? null;
        self::assertIsArray($errors);
        $entries = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $entries[] = $error;
        }

        return $entries;
    }

    /**
     * @param list<array<array-key, mixed>> $errors
     *
     * @return array{class: mixed, message: mixed}
     */
    private function kernelBootCause(array $errors): array
    {
        $cause = $errors[0]['cause'] ?? null;
        self::assertIsArray($cause);
        $chain = $cause['chain'] ?? null;
        self::assertIsArray($chain);
        $first = $chain[0] ?? null;
        self::assertIsArray($first);

        return ['class' => $first['class'] ?? null, 'message' => $first['message'] ?? null];
    }

    /** @return list<string> */
    private function cacheEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->workspace->path('var/cache')) ?: [], ['.', '..']));
        $this->workspace->remove('var');

        return $entries;
    }

    public function testNormalizesSecurityMetadataWithoutExportingProviderValues(): void
    {
        (new SecurityFixtureBuilder($this->workspace))->writeSecurityApplication();

        $process = $this->bridge->run(['--sections=security']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['security'] ?? null);
        self::assertSame([
            ['name' => 'main', 'provider' => 'users', 'enabled' => true, 'stateless' => true, 'lazy' => false, 'authenticators' => ['App\\Security\\Authenticator']],
        ], $result['sections']['security']['firewalls'] ?? null);
        self::assertSame([['name' => 'users', 'type' => 'memory']], $result['sections']['security']['providers'] ?? null);
        self::assertSame([
            ['name' => 'ROLE_ADMIN', 'inheritedRoles' => ['ROLE_USER']],
            ['name' => 'ROLE_USER', 'inheritedRoles' => []],
        ], $result['sections']['security']['roles'] ?? null);
        self::assertSame([['class' => 'App\\Security\\PostVoter']], $result['sections']['security']['voters'] ?? null);
    }

    public function testReportsUnavailableOptionalAssetMapper(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('8.0.6');

        $process = $this->bridge->run(['--sections=assets']);

        $result = $process->snapshot;
        self::assertSame(0, $process->exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['assets'] ?? null);
        self::assertFalse($result['sections']['assets']['assetsComplete'] ?? null);
        self::assertFalse($result['sections']['assets']['importMapComplete'] ?? null);
        self::assertSame([], $result['sections']['assets']['assets'] ?? null);
        self::assertSame([], $result['sections']['assets']['importMap'] ?? null);
    }

    public function testCollectsBundleControllersAndStaysIncompleteWithoutTheConfiguredRegistry(): void
    {
        (new StimulusFixtureBuilder($this->workspace))->writeThemedStimulusApplication();

        $process = $this->bridge->run(['--sections=stimulus']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        $stimulus = $result['sections']['stimulus'] ?? null;
        self::assertIsArray($stimulus);
        self::assertFalse($stimulus['complete'] ?? null);
        self::assertContains('The configured controllers.json was not found.', \is_array($stimulus['warnings'] ?? null) ? $stimulus['warnings'] : []);
        self::assertContains('acme--widget', array_column(\is_array($stimulus['controllers'] ?? null) ? $stimulus['controllers'] : [], 'name'));
    }

    public function testExportsDoctrineMetadataFromTheMetadataFactory(): void
    {
        (new DoctrineFixtureBuilder($this->workspace))->writeDoctrineApplication();

        $process = $this->bridge->run(['--sections=doctrine']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        $doctrine = $result['sections']['doctrine'] ?? null;
        self::assertIsArray($doctrine);
        self::assertTrue($doctrine['complete'] ?? null);
        self::assertIsArray($doctrine['entities'] ?? null);
        $entity = $doctrine['entities'][0] ?? null;
        self::assertIsArray($entity);
        self::assertSame('App\Entity\Book', $entity['className'] ?? null);
        self::assertSame(realpath($this->workspace->path).'/src/Entity/Book.php', $entity['file'] ?? null);
        self::assertSame('App\Repository\BookRepository', $entity['repositoryClass'] ?? null);
        self::assertSame([
            ['name' => 'title', 'type' => 'string', 'association' => false, 'targetEntity' => null],
            ['name' => 'author', 'type' => null, 'association' => true, 'targetEntity' => 'App\Entity\Author'],
        ], $entity['fields'] ?? null);
    }

    public function testReportsUnavailableOptionalStimulusBundle(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('8.0.6');

        $process = $this->bridge->run(['--sections=stimulus']);

        $result = $process->snapshot;
        self::assertSame(0, $process->exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['stimulus'] ?? null);
        self::assertFalse($result['sections']['stimulus']['complete'] ?? null);
        self::assertSame([], $result['sections']['stimulus']['controllers'] ?? null);
    }

    public function testReportsUnavailableOptionalMetadataComponents(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('8.0.6');

        $process = $this->bridge->run(['--sections=metadata']);

        $result = $process->snapshot;
        self::assertSame(0, $process->exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['metadata'] ?? null);
        self::assertFalse($result['sections']['metadata']['formsComplete'] ?? null);
        self::assertFalse($result['sections']['metadata']['constraintsComplete'] ?? null);
        self::assertSame([], $result['sections']['metadata']['forms'] ?? null);
        self::assertSame([], $result['sections']['metadata']['constraints'] ?? null);
    }

    public function testKeepsConstraintMetadataWithoutOptionalDependencies(): void
    {
        (new MetadataFixtureBuilder($this->workspace))->writeConstraintApplication();

        $process = $this->bridge->run(['--sections=metadata']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        $metadata = $result['sections']['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertTrue($metadata['constraintsComplete'] ?? null);
        self::assertSame([
            ['name' => 'Alpha', 'class' => 'Symfony\\Component\\Validator\\Constraints\\Alpha', 'options' => ['min']],
            ['name' => 'Zulu', 'class' => 'Symfony\\Component\\Validator\\Constraints\\Zulu', 'options' => ['max']],
        ], $metadata['constraints'] ?? null);
    }

    public function testDerivesLoaderPathsWhenAThemeLoaderHidesThem(): void
    {
        (new TwigFixtureBuilder($this->workspace))->writeThemedTwigApplication();

        $process = $this->bridge->run(['--sections=twig']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['twig'] ?? null);
        $paths = $result['sections']['twig']['paths'] ?? null;
        self::assertIsArray($paths);
        $byNamespace = [];
        foreach ($paths as $path) {
            self::assertIsArray($path);
            self::assertIsString($path['namespace'] ?? null);
            $byNamespace[$path['namespace']][] = $path['path'];
        }
        self::assertSame([realpath($this->workspace->path).'/src/ShopBundle/templates'], $byNamespace['@Shop'] ?? null);
        self::assertSame([realpath($this->workspace->path).'/templates'], $byNamespace['(None)'] ?? null);
    }

    public function testReportsUnavailableTwigDebugCommandAsAWarning(): void
    {
        (new TwigFixtureBuilder($this->workspace))->writeTwigApplicationWithoutDebugCommand();

        $process = $this->bridge->run(['--sections=twig']);

        $result = $process->snapshot;
        self::assertSame(0, $process->exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertSame([
            'complete' => false,
            'generation' => hash('sha256', '[[],[]]'),
            'paths' => [],
            'globals' => [],
            'resources' => [],
            'warnings' => ['The debug:twig command is unavailable.'],
        ], $result['sections']['twig'] ?? null);
    }

    public function testIgnoresAnUnregisteredSecurityBundle(): void
    {
        (new SecurityFixtureBuilder($this->workspace))->writeUnregisteredSecurityApplication();

        $process = $this->bridge->run(['--sections=security']);

        $result = $process->snapshot;
        self::assertSame(0, $process->exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['security'] ?? null);
        self::assertSame([], $result['sections']['security']['firewalls'] ?? null);
    }

    public function testKeepsConfiguredMessengerRoutingWhenAddingHandlerMessages(): void
    {
        $script = <<<'PHP'
            require $argv[1].'/resources/bridge/sections/messenger.php';
            echo json_encode(symfonyLspBridgeMessengerMergeMessages(
                ['App\\Message\\Configured' => ['class' => 'App\\Message\\Configured', 'transports' => ['async']]],
                [
                    'App\\Message\\Configured' => ['class' => 'App\\Message\\Configured', 'transports' => []],
                    'App\\Message\\HandlerOnly' => ['class' => 'App\\Message\\HandlerOnly', 'transports' => []],
                ],
            ), JSON_THROW_ON_ERROR);
            PHP;
        exec(\sprintf('%s -r %s %s', escapeshellarg(\PHP_BINARY), escapeshellarg($script), escapeshellarg(\dirname(__DIR__, 2))), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame([
            'App\\Message\\Configured' => ['class' => 'App\\Message\\Configured', 'transports' => ['async']],
            'App\\Message\\HandlerOnly' => ['class' => 'App\\Message\\HandlerOnly', 'transports' => []],
        ], json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR));
    }
}
