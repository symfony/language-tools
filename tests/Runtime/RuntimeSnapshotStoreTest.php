<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotStore;

final class RuntimeSnapshotStoreTest extends TestCase
{
    private string $temporaryDirectory;
    private string $bridge;
    private Project $project;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-runtime-store-'.bin2hex(random_bytes(8));
        $this->bridge = $this->temporaryDirectory.'/bridge/bridge.php';
        $this->project = new Project($this->temporaryDirectory.'/project', 'file://'.$this->temporaryDirectory.'/project');
        mkdir(\dirname($this->bridge), 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testSavesAndLoadsAcrossStoreInstances(): void
    {
        $configuration = new RuntimeConfiguration();
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($this->project, $this->bridge, [
            'schemaVersion' => 1,
            'generation' => 'discarded',
            'project' => [
                'root' => '/container/project',
                'symfonyVersion' => '8.1.0',
                'symfonyBranch' => '8.1',
                'phpVersion' => '8.4.1',
                'environment' => 'prod',
                'debug' => false,
                'secret' => 'discarded',
            ],
            'configurationValidation' => ['status' => 'valid'],
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
                'container' => ['complete' => true, 'items' => []],
            ],
            'errors' => [['section' => 'routes', 'message' => 'discarded']],
        ], ['routes', 'container'], true);

        $loaded = (new RuntimeSnapshotStore($configuration, new Filesystem()))->load($this->project, $this->bridge);

        self::assertSame([
            'schemaVersion' => 1,
            'project' => [
                'root' => $this->project->rootPath,
                'environment' => 'dev',
                'debug' => true,
            ],
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
                'container' => ['complete' => true, 'items' => []],
            ],
        ], $loaded?->snapshot);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $loaded->lastSuccessfulAt);

        $persisted = file_get_contents($this->snapshotPath());
        self::assertIsString($persisted);
        self::assertStringNotContainsString('configurationValidation', $persisted);
        self::assertStringNotContainsString('discarded', $persisted);
        self::assertStringNotContainsString('errors', $persisted);
    }

    public function testMergesAndRemovesTargetedSections(): void
    {
        $configuration = new RuntimeConfiguration();
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($this->project, $this->bridge, [
            'schemaVersion' => 1,
            'project' => ['symfonyVersion' => '8.1.0'],
            'sections' => [
                'routes' => ['items' => [['name' => 'old']]],
                'container' => ['items' => [['id' => 'mailer']]],
                'twig_components' => ['items' => [['name' => 'Alert']]],
            ],
        ], ['routes', 'container', 'twig_components'], true);

        (new RuntimeSnapshotStore($configuration, new Filesystem()))->save($this->project, $this->bridge, [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['items' => [['name' => 'new']]],
                'twig_components' => ['items' => [['name' => 'Ignored']]],
            ],
        ], ['routes', 'container'], false);

        $loaded = (new RuntimeSnapshotStore($configuration, new Filesystem()))->load($this->project, $this->bridge);
        self::assertSame([
            'schemaVersion' => 1,
            'project' => [
                'root' => $this->project->rootPath,
                'environment' => 'dev',
                'debug' => true,
            ],
            'sections' => [
                'routes' => ['items' => [['name' => 'new']]],
                'twig_components' => ['items' => [['name' => 'Alert']]],
            ],
        ], $loaded?->snapshot);
    }

    public function testPersistsAvailableSectionsFromPartialRefreshes(): void
    {
        $configuration = new RuntimeConfiguration();
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->savePartial($this->project, $this->bridge, [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['items' => [['name' => 'initial']]],
            ],
        ], ['routes']);
        $store->savePartial($this->project, $this->bridge, [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['items' => [['name' => 'replacement']]],
                'container' => ['items' => [['id' => 'mailer']]],
            ],
        ], ['container']);

        self::assertSame([
            'routes' => ['items' => [['name' => 'initial']]],
            'container' => ['items' => [['id' => 'mailer']]],
        ], $store->load($this->project, $this->bridge)?->snapshot['sections'] ?? null);
    }

    public function testCreatesSnapshotsOnlyFromCompleteRefreshes(): void
    {
        $store = new RuntimeSnapshotStore(new RuntimeConfiguration(), new Filesystem());
        $store->save(
            $this->project,
            $this->bridge,
            ['schemaVersion' => 1, 'sections' => ['routes' => ['items' => []]]],
            ['routes'],
            false,
        );
        self::assertNull($store->load($this->project, $this->bridge));

        $store = $this->storeWithSnapshot();
        $store->save(
            $this->project,
            $this->bridge,
            ['schemaVersion' => 1, 'sections' => []],
            ['routes'],
            true,
        );
        self::assertNull($store->load($this->project, $this->bridge));
    }

    public function testSeparatesEveryRuntimeConfigurationDimension(): void
    {
        $configuration = new RuntimeConfiguration();
        (new RuntimeSnapshotStore($configuration, new Filesystem()))->save(
            $this->project,
            $this->bridge,
            ['schemaVersion' => 1, 'sections' => ['routes' => ['items' => []]]],
            ['routes'],
            true,
        );

        $otherProject = new Project($this->project->rootPath.'-other', 'file://'.$this->project->rootPath.'-other');
        $phpCommand = new RuntimeConfiguration();
        $phpCommand->configure(['phpCommand' => ['custom-php']]);
        $containerRoot = new RuntimeConfiguration();
        $containerRoot->configure(['containerProjectRoot' => '/app']);
        $environment = new RuntimeConfiguration();
        $environment->configure(['environment' => 'test']);
        $debug = new RuntimeConfiguration();
        $debug->configure(['debug' => false]);

        foreach ([
            'project root' => [$otherProject, new RuntimeConfiguration()],
            'PHP command' => [$this->project, $phpCommand],
            'container project root' => [$this->project, $containerRoot],
            'environment' => [$this->project, $environment],
            'debug' => [$this->project, $debug],
        ] as $dimension => [$project, $changedConfiguration]) {
            self::assertNull(
                (new RuntimeSnapshotStore($changedConfiguration, new Filesystem()))->load($project, $this->bridge),
                $dimension,
            );
        }
    }

    public function testRejectsCorruptionAndIntegrityMismatch(): void
    {
        $store = $this->storeWithSnapshot();
        $path = $this->snapshotPath();
        file_put_contents($path, '{');
        self::assertNull($store->load($this->project, $this->bridge));

        $store = $this->storeWithSnapshot();
        $path = $this->snapshotPath();
        $persisted = file_get_contents($path);
        self::assertIsString($persisted);
        file_put_contents($path, str_replace('homepage', 'tampered', $persisted));
        self::assertNull($store->load($this->project, $this->bridge));
    }

    public function testRejectsIncompatibleOrMismatchedSnapshots(): void
    {
        $store = $this->storeWithSnapshot();
        $path = $this->snapshotPath();
        /** @var array{formatVersion: int, lastSuccessfulAt: string, payloadHash: string, payload: array{schemaVersion: int, project: array<string, mixed>, sections: array<string, mixed>}} $envelope */
        $envelope = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $variants = [];
        $variant = $envelope;
        $variant['formatVersion'] = 2;
        $variants['format version'] = $variant;
        $variant = $envelope;
        $variant['payload']['schemaVersion'] = 2;
        $variants['snapshot schema'] = $this->withUpdatedIntegrity($variant);
        $variant = $envelope;
        $variant['payload']['project']['root'] = $this->project->rootPath.'-other';
        $variants['project root'] = $this->withUpdatedIntegrity($variant);
        $variant = $envelope;
        $variant['payload']['project']['environment'] = 'prod';
        $variants['environment'] = $this->withUpdatedIntegrity($variant);
        $variant = $envelope;
        $variant['payload']['project']['debug'] = false;
        $variants['debug'] = $this->withUpdatedIntegrity($variant);
        $variant = $envelope;
        $variant['payload']['sections'] = [];
        $variants['empty sections'] = $this->withUpdatedIntegrity($variant);

        foreach ($variants as $reason => $variant) {
            file_put_contents($path, json_encode($variant, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            self::assertNull($store->load($this->project, $this->bridge), $reason);
        }
    }

    public function testWriteFailurePreservesThePreviousSnapshot(): void
    {
        $store = $this->storeWithSnapshot();
        (new RuntimeSnapshotStore(new RuntimeConfiguration(), new FailingRuntimeSnapshotFilesystem()))->save(
            $this->project,
            $this->bridge,
            [
                'schemaVersion' => 1,
                'sections' => ['routes' => ['items' => [['name' => 'replacement']]]],
            ],
            ['routes'],
            false,
        );

        self::assertSame(
            ['routes' => ['items' => [['name' => 'homepage']]]],
            $store->load($this->project, $this->bridge)?->snapshot['sections'] ?? null,
        );
    }

    private function storeWithSnapshot(): RuntimeSnapshotStore
    {
        $store = new RuntimeSnapshotStore(new RuntimeConfiguration(), new Filesystem());
        $store->save(
            $this->project,
            $this->bridge,
            [
                'schemaVersion' => 1,
                'sections' => ['routes' => ['items' => [['name' => 'homepage']]]],
            ],
            ['routes'],
            true,
        );

        return $store;
    }

    private function snapshotPath(): string
    {
        $paths = glob(\dirname($this->bridge).'/runtime/*.json');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);

        return $paths[0];
    }

    /**
     * @param array{formatVersion: int, lastSuccessfulAt: string, payloadHash: string, payload: array{schemaVersion: int, project: array<string, mixed>, sections: array<string, mixed>}} $envelope
     *
     * @return array{formatVersion: int, lastSuccessfulAt: string, payloadHash: string, payload: array{schemaVersion: int, project: array<string, mixed>, sections: array<string, mixed>}}
     */
    private function withUpdatedIntegrity(array $envelope): array
    {
        $payload = json_encode($envelope['payload'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $envelope['payloadHash'] = hash('sha256', $payload);

        return $envelope;
    }
}

final class FailingRuntimeSnapshotFilesystem extends Filesystem
{
    public function dumpFile(string $filename, $content): void
    {
        throw new IOException('Unable to persist the runtime snapshot.');
    }
}
