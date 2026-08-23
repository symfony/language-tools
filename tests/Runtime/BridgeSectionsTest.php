<?php

namespace Symfony\Lsp\Tests\Runtime;

final class BridgeSectionsTest extends AbstractBridgeTestCase
{
    public function testExportsEnvironmentProcessorMetadataWithoutValues(): void
    {
        $this->writeEnvironmentApplication();
        $previousSecret = getenv('APP_SECRET');
        putenv('APP_SECRET=CANARY_SECRET_ENVIRONMENT_VALUE');

        exec(\sprintf(
            '%s %s --project=%s --sections=environment 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);
        if (false === $previousSecret) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET='.$previousSecret);
        }

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeEventApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=events 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeRuntimeFrontControllerApplication();
        $previousOptions = getenv('APP_RUNTIME_OPTIONS');
        putenv('APP_RUNTIME_OPTIONS={"environment_option":"environment"}');

        exec(\sprintf(
            '%s %s --project=%s --sections=events 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);
        if (false === $previousOptions) {
            putenv('APP_RUNTIME_OPTIONS');
        } else {
            putenv('APP_RUNTIME_OPTIONS='.$previousOptions);
        }

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null, $snapshot);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['events'] ?? null);
        self::assertSame([
            ['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10],
        ], $result['sections']['events']['listeners'] ?? null);
    }

    public function testNormalizesSecurityMetadataWithoutExportingProviderValues(): void
    {
        $this->writeSecurityApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=security 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=assets 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
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
        $this->writeThemedStimulusApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=stimulus 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeDoctrineApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=doctrine 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        self::assertSame(realpath($this->temporaryDirectory).'/src/Entity/Book.php', $entity['file'] ?? null);
        self::assertSame('App\Repository\BookRepository', $entity['repositoryClass'] ?? null);
        self::assertSame([
            ['name' => 'title', 'type' => 'string', 'association' => false, 'targetEntity' => null],
            ['name' => 'author', 'type' => null, 'association' => true, 'targetEntity' => 'App\Entity\Author'],
        ], $entity['fields'] ?? null);
    }

    public function testReportsUnavailableOptionalStimulusBundle(): void
    {
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=stimulus 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['stimulus'] ?? null);
        self::assertFalse($result['sections']['stimulus']['complete'] ?? null);
        self::assertSame([], $result['sections']['stimulus']['controllers'] ?? null);
    }

    public function testReportsUnavailableOptionalMetadataComponents(): void
    {
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=metadata 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['metadata'] ?? null);
        self::assertFalse($result['sections']['metadata']['formsComplete'] ?? null);
        self::assertFalse($result['sections']['metadata']['constraintsComplete'] ?? null);
        self::assertSame([], $result['sections']['metadata']['forms'] ?? null);
        self::assertSame([], $result['sections']['metadata']['constraints'] ?? null);
    }

    public function testDerivesLoaderPathsWhenAThemeLoaderHidesThem(): void
    {
        $this->writeThemedTwigApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=twig 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        self::assertSame([realpath($this->temporaryDirectory).'/src/ShopBundle/templates'], $byNamespace['@Shop'] ?? null);
        self::assertSame([realpath($this->temporaryDirectory).'/templates'], $byNamespace['(None)'] ?? null);
    }

    public function testReportsUnavailableTwigDebugCommandAsAWarning(): void
    {
        $this->writeTwigApplicationWithoutDebugCommand();

        exec(\sprintf(
            '%s %s --project=%s --sections=twig 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
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
        $this->writeUnregisteredSecurityApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=security 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
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
            echo json_encode(bridgeMessengerMergeMessages(
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
