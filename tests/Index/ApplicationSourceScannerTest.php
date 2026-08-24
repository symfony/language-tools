<?php

namespace Symfony\Lsp\Tests\Index;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Sync\LocalKeyedMutex;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Security\SecurityExtractor;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexer;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexRegistry;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\NullProgressReporter;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class ApplicationSourceScannerTest extends TestCase
{
    private string $temporaryDirectory;
    private Project $project;
    private ProjectRegistry $projects;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/src', 0777, true);
        $this->project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $this->projects = new ProjectRegistry();
        $this->projects->replace([$this->project]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testIndexesNestedProjectFilesOnlyForTheirMostSpecificProject(): void
    {
        mkdir($this->temporaryDirectory.'/nested/src', 0777, true);
        file_put_contents($this->temporaryDirectory.'/src/Parent.php', '<?php final class ParentClass {}');
        file_put_contents($this->temporaryDirectory.'/nested/src/Child.php', '<?php final class ChildClass {}');
        $child = new Project(
            $this->temporaryDirectory.'/nested',
            'file://'.$this->temporaryDirectory.'/nested',
            '^8.0',
        );
        $this->projects->replace([$this->project, $child]);
        $provider = new RecordingSourceIndexProvider();

        $this->scanner($provider)->indexAll();

        self::assertSame(2, $provider->extractions);
    }

    public function testRestoresPersistentFactsAndRebuildsCorruptedEntries(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $firstProvider = new RecordingSourceIndexProvider();
        $this->scanner($firstProvider)->indexAll();

        self::assertSame(1, $firstProvider->extractions);
        self::assertSame(0, $firstProvider->restores);

        $cachePath = $this->temporaryDirectory.'/var/symfony-lsp/test/index/source.jsonl';
        $this->rewriteCacheRecord($cachePath, 'src/Controller.php', static function (array $record): array {
            unset($record['runtimeStructure']);

            return $record;
        });

        $secondProvider = new RecordingSourceIndexProvider();
        $this->scanner($secondProvider)->indexAll();

        self::assertSame(0, $secondProvider->extractions);
        self::assertSame(1, $secondProvider->restores);

        $this->rewriteCacheRecord($cachePath, 'src/Controller.php', static function (array $record): array {
            $providers = $record['providers'];
            \assert(\is_array($providers));
            $providers['recording'] = 'invalid';
            $record['providers'] = $providers;

            return $record;
        });

        $thirdProvider = new RecordingSourceIndexProvider();
        $this->scanner($thirdProvider)->indexAll();

        self::assertSame(1, $thirdProvider->extractions);
        self::assertSame(0, $thirdProvider->restores);

        $this->rewriteCacheRecord($cachePath, 'src/Controller.php', static function (array $record): array {
            $range = new Range(new Position(0, 0), new Position(0, 0));
            $facts = new RouteSourceFacts('file:///src/Controller.php', [new RouteDeclaration('route', 'file:///src/Controller.php', $range)], []);
            $providers = $record['providers'];
            \assert(\is_array($providers));
            $providers['recording'] = base64_encode(str_replace(RouteDeclaration::class, MissingPayloadData::class, serialize($facts)));
            $record['providers'] = $providers;

            return $record;
        });

        $fourthProvider = new RecordingSourceIndexProvider();
        $this->scanner($fourthProvider)->indexAll();

        self::assertSame(1, $fourthProvider->extractions);
        self::assertSame(0, $fourthProvider->restores);
    }

    public function testStoresEmptyFactsAsMarkersAndSkipsTheirRestores(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Empty.php', '<?php final class Empty1 {}');
        file_put_contents($this->temporaryDirectory.'/src/Full.php', '<?php final class Full {}');
        $firstProvider = new ObjectFactsSourceIndexProvider(['src/Full.php']);
        $this->scanner($firstProvider)->indexAll();

        $cache = (string) file_get_contents($this->temporaryDirectory.'/var/symfony-lsp/test/index/source.jsonl');
        self::assertStringContainsString('"objectFacts":""', $cache);
        self::assertSame(2, $firstProvider->extractions);

        $secondProvider = new ObjectFactsSourceIndexProvider(['src/Full.php']);
        $this->scanner($secondProvider)->indexAll();

        self::assertSame(0, $secondProvider->extractions);
        self::assertSame(1, $secondProvider->restores);
        self::assertSame(['file://'.$this->temporaryDirectory.'/src/Full.php'], $secondProvider->restoredUris);
    }

    /** @param list<string> $classes */
    #[DataProvider('invalidPayloadSchemaProvider')]
    public function testRejectsInvalidPayloadSchemas(array $classes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SourceIndexPayloadCodec())->validate([new RecordingSourceIndexProvider(payloadClasses: $classes)]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidPayloadSchemaProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown class' => [['MissingPayloadClass']];
        yield 'duplicate class' => [[RouteSourceFacts::class, RouteSourceFacts::class]];
        yield 'shared class' => [[Range::class]];
    }

    public function testRejectsPayloadClassesOwnedByDifferentProviders(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SourceIndexPayloadCodec())->validate([
            new ObjectFactsSourceIndexProvider([]),
            new RecordingSourceIndexProvider(),
        ]);
    }

    public function testRejectsUndeclaredRootPayloadClasses(): void
    {
        $provider = new RecordingSourceIndexProvider(payloadClasses: [RouteDeclaration::class]);
        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$provider]);
        $this->expectException(\UnexpectedValueException::class);

        $codec->encode($provider->name(), new RouteSourceFacts('file:///source.php', [], []));
    }

    public function testRejectsIncompletePayloadObjectGraphs(): void
    {
        $provider = new RecordingSourceIndexProvider(payloadClasses: [RouteSourceFacts::class]);
        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$provider]);
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $payload = base64_encode(serialize(new RouteSourceFacts('file:///source.php', [new RouteDeclaration('route', 'file:///source.php', $range)], [])));
        $this->expectException(\UnexpectedValueException::class);

        $codec->decode($provider->name(), $payload);
    }

    public function testReportsContentOnlyChangesWhenEmptyFactsGainNoRuntimeDeclarations(): void
    {
        $path = $this->temporaryDirectory.'/src/Empty.php';
        file_put_contents($path, '<?php final class Empty1 {}');
        $provider = new ObjectFactsSourceIndexProvider([]);
        $scanner = $this->scanner($provider);
        $scanner->indexAll();

        file_put_contents($path, '<?php final class Empty2 {}');

        self::assertSame([], $scanner->refreshUri('file://'.$path)->domains());
    }

    public function testReportsChangesLimitedToRouteFacts(): void
    {
        $path = $this->temporaryDirectory.'/src/Controller.php';
        file_put_contents($path, '<?php final class FirstController {}');
        $scanner = $this->scanner(new RecordingSourceIndexProvider('routes'));
        $scanner->indexAll();

        file_put_contents($path, '<?php final class SecondController {}');

        self::assertSame(['routes'], $scanner->refreshUri('file://'.$path)->domains());
    }

    public function testReportsEveryChangedSourceDomain(): void
    {
        $path = $this->temporaryDirectory.'/src/Service.php';
        file_put_contents($path, '<?php final class FirstService {}');
        $scanner = $this->scanner(
            new RecordingSourceIndexProvider('events'),
            new RecordingSourceIndexProvider('messenger'),
        );
        $scanner->indexAll();

        file_put_contents($path, '<?php final class SecondService {}');

        self::assertSame(['events', 'messenger'], $scanner->refreshUri('file://'.$path)->domains());
    }

    #[DataProvider('referenceChangeProvider')]
    public function testReportsOnlyRuntimeRelevantReferenceChanges(string $relativePath, bool $requiresRuntimeRefresh): void
    {
        $path = $this->temporaryDirectory.'/'.$relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        $source = <<<'PHP'
<?php
use Symfony\Component\Security\Http\Attribute\IsGranted;
final class Controller
{
    public function index(): void {}
}
PHP;
        file_put_contents($path, $source);
        $scanner = $this->scanner($this->securityIndexer());
        $scanner->indexAll();

        file_put_contents($path, str_replace('    public function index()', "    #[IsGranted('ROLE_ADMIN')]\n    public function index()", $source));

        self::assertSame($requiresRuntimeRefresh, $scanner->refreshUri('file://'.$path)->requiresRuntimeRefresh());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function referenceChangeProvider(): iterable
    {
        yield 'ordinary source' => ['src/Controller.php', false];
        yield 'executed source' => ['src/EventSubscriber/Controller.php', true];
    }

    public function testKeepsConfigurationReferenceChangesForRuntimePlanning(): void
    {
        $path = $this->temporaryDirectory.'/config/packages/security.yaml';
        mkdir(\dirname($path), 0777, true);
        file_put_contents($path, "security:\n  access_control:\n    - { path: ^/admin, roles: ROLE_ADMIN }\n");
        $scanner = $this->scanner($this->securityIndexer());
        $scanner->indexAll();

        file_put_contents($path, "security:\n  access_control:\n    - { path: ^/admin, roles: ROLE_SUPER_ADMIN }\n");

        self::assertTrue($scanner->refreshUri('file://'.$path)->requiresRuntimeRefresh());
    }

    public function testLeavesNewFilesForPathBasedRuntimePlanning(): void
    {
        $scanner = $this->scanner(new RecordingSourceIndexProvider('routes'));
        $scanner->indexAll();
        $path = $this->temporaryDirectory.'/src/NewController.php';
        file_put_contents($path, '<?php final class NewController {}');

        $change = $scanner->refreshUri('file://'.$path);

        self::assertTrue($change->requiresRuntimeRefresh());
        self::assertSame([], $change->domains());
    }

    public function testPersistentFactsNeverContainEnvironmentValues(): void
    {
        file_put_contents($this->temporaryDirectory.'/.env', "APP_SECRET=canary-value\n");
        $indexes = new EnvironmentIndexRegistry();
        $this->scanner(new EnvironmentSourceIndexer(
            $indexes,
            new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser()),
        ))->indexAll();

        self::assertSame(['APP_SECRET'], $indexes->forProject($this->project)->names());
        $cache = (string) file_get_contents($this->temporaryDirectory.'/var/symfony-lsp/test/index/source.jsonl');
        self::assertStringNotContainsString('canary-value', $cache);
    }

    public function testSkipsPackageManagerLockFiles(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', '{}');
        file_put_contents($this->temporaryDirectory.'/package-lock.json', '{}');
        file_put_contents($this->temporaryDirectory.'/npm-shrinkwrap.json', '{}');
        file_put_contents($this->temporaryDirectory.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        $provider = new RecordingSourceIndexProvider();

        $this->scanner($provider)->indexAll();

        $rootUri = 'file://'.$this->temporaryDirectory;
        self::assertArrayHasKey($rootUri.'/composer.json', $provider->sources);
        self::assertArrayNotHasKey($rootUri.'/package-lock.json', $provider->sources);
        self::assertArrayNotHasKey($rootUri.'/npm-shrinkwrap.json', $provider->sources);
        self::assertArrayNotHasKey($rootUri.'/pnpm-lock.yaml', $provider->sources);
    }

    public function testHonorsGitignoreWhileKeepingDotenvFiles(): void
    {
        mkdir($this->temporaryDirectory.'/.git');
        mkdir($this->temporaryDirectory.'/tmp/phpstan', 0777, true);
        file_put_contents($this->temporaryDirectory.'/.gitignore', "/tmp/\n/.env.local\n");
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        file_put_contents($this->temporaryDirectory.'/tmp/phpstan/cache.php', '<?php return [];');
        file_put_contents($this->temporaryDirectory.'/.env.local', "APP_DEBUG=1\n");
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scanner($provider);

        $scanner->indexAll();

        $rootUri = 'file://'.$this->temporaryDirectory;
        self::assertArrayHasKey($rootUri.'/src/Controller.php', $provider->sources);
        self::assertArrayHasKey($rootUri.'/.env.local', $provider->sources);
        self::assertArrayNotHasKey($rootUri.'/tmp/phpstan/cache.php', $provider->sources);
    }

    public function testLeavesSavedGitignoredFilesOutOfTheIndex(): void
    {
        mkdir($this->temporaryDirectory.'/.git');
        mkdir($this->temporaryDirectory.'/tmp');
        file_put_contents($this->temporaryDirectory.'/.gitignore', "/tmp/\n");
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $scanner->indexAll();

        $path = $this->temporaryDirectory.'/tmp/cache.php';
        file_put_contents($path, '<?php return [];');
        $change = $scanner->refreshUri('file://'.$path);

        self::assertFalse($change->requiresRuntimeRefresh());
        self::assertSame([], $provider->replacements);
        self::assertArrayNotHasKey('file://'.$path, $provider->sources);
    }

    public function testRemovesPreviouslyIndexedFilesWhenTheyBecomeGitignored(): void
    {
        mkdir($this->temporaryDirectory.'/.git');
        $path = $this->temporaryDirectory.'/src/Controller.php';
        $uri = 'file://'.$path;
        file_put_contents($path, '<?php final class Controller {}');
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $scanner->indexAll();
        file_put_contents($this->temporaryDirectory.'/.gitignore', "/src/Controller.php\n");

        $change = $scanner->refreshUri($uri);

        self::assertFalse($change->requiresRuntimeRefresh());
        self::assertSame([$uri], $provider->removals);
        self::assertArrayNotHasKey($uri, $provider->sources);
    }

    public function testDoesNotOverlayOpenDependencyOwnedFiles(): void
    {
        mkdir($this->temporaryDirectory.'/vendor');
        $uri = 'file://'.$this->temporaryDirectory.'/vendor/Dependency.php';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, '<?php final class Dependency {}'));
        $provider = new RecordingSourceIndexProvider();

        $this->scannerWithDocuments($documents, $provider)->updateOpenDocument([
            'textDocument' => ['uri' => $uri],
        ]);

        self::assertSame([], $provider->overlays);
    }

    public function testIndexesReadableSourcesAroundUnreadableDirectories(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }

        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        mkdir($this->temporaryDirectory.'/volumes/mysql', 0777, true);
        chmod($this->temporaryDirectory.'/volumes/mysql', 0000);
        $provider = new RecordingSourceIndexProvider();

        try {
            $this->scanner($provider)->indexAll();
        } finally {
            chmod($this->temporaryDirectory.'/volumes/mysql', 0755);
        }

        self::assertSame(1, $provider->extractions);
        self::assertFileExists($this->temporaryDirectory.'/var/symfony-lsp/test/index/source.jsonl');
    }

    public function testRestoresCycleCollectionAfterScanning(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');

        gc_enable();
        $this->scanner(new RecordingSourceIndexProvider())->indexAll();

        self::assertTrue(gc_enabled());
    }

    public function testRestoresCycleCollectionAfterCancellation(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        gc_enable();
        try {
            $this->scanner(new RecordingSourceIndexProvider())->refreshProject($this->project, $cancellation->getCancellation());
            self::fail('The scan should have been canceled.');
        } catch (CancelledException) {
        }

        self::assertTrue(gc_enabled());
    }

    public function testLeavesDisabledCycleCollectionUntouched(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');

        gc_disable();
        try {
            $this->scanner(new RecordingSourceIndexProvider())->indexAll();
            self::assertFalse(gc_enabled());
        } finally {
            gc_enable();
        }
    }

    public function testUpdatesAndDeletesIndividualFiles(): void
    {
        $firstPath = $this->temporaryDirectory.'/src/First.php';
        $secondPath = $this->temporaryDirectory.'/src/Second.php';
        file_put_contents($firstPath, '<?php final class FirstVersion { public function value(): int { return 1; } }');
        file_put_contents($secondPath, '<?php final class Second {}');
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $scanner->indexAll();
        $provider->replacements = [];

        file_put_contents($firstPath, '<?php final class FirstVersion { public function value(): int { return 2; } }');
        $firstUri = 'file://'.$firstPath;
        self::assertFalse($scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]])->requiresRuntimeRefresh());

        self::assertSame([$firstUri], $provider->replacements);
        self::assertCount(2, $provider->sources);
        self::assertSame(hash('sha256', '<?php final class FirstVersion { public function value(): int { return 2; } }'), $provider->sources[$firstUri]);

        file_put_contents($firstPath, '<?php final class NewFirstVersion { public function value(): int { return 2; } }');
        self::assertSame(['recording'], $scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]])->domains());
        self::assertSame([$firstUri, $firstUri], $provider->replacements);

        touch($firstPath, time() + 1);
        self::assertFalse($scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]])->requiresRuntimeRefresh());
        self::assertSame([$firstUri, $firstUri], $provider->replacements);

        $secondUri = 'file://'.$secondPath;
        unlink($secondPath);
        $scanner->refreshUri($secondUri, true);

        self::assertSame([$secondUri], $provider->removals);
        self::assertSame([$firstUri => hash('sha256', '<?php final class NewFirstVersion { public function value(): int { return 2; } }')], $provider->sources);
    }

    public function testAnIncrementalSaveSurvivesAConcurrentFullScan(): void
    {
        for ($i = 0; $i < 80; ++$i) {
            file_put_contents(\sprintf('%s/src/File%02d.php', $this->temporaryDirectory, $i), \sprintf('<?php final class File%02d {}', $i));
        }
        $provider = new GenerationalSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $scan = async(fn () => $scanner->refreshProject($this->project));
        delay(0);
        self::assertNotSame([], $provider->staged[$this->temporaryDirectory] ?? []);

        $uri = (string) array_key_first($provider->staged[$this->temporaryDirectory]);
        file_put_contents(substr($uri, \strlen('file://')), '<?php final class Saved {}');
        $refresh = async(static fn () => $scanner->refreshUri($uri));
        await([$scan, $refresh]);

        self::assertSame(hash('sha256', '<?php final class Saved {}'), $provider->committed[$this->temporaryDirectory][$uri]);
        self::assertFalse($scanner->refreshUri($uri)->requiresRuntimeRefresh());
    }

    public function testANewFullScanSupersedesTheActiveScan(): void
    {
        for ($i = 0; $i < 80; ++$i) {
            file_put_contents(\sprintf('%s/src/File%02d.php', $this->temporaryDirectory, $i), \sprintf('<?php final class File%02d {}', $i));
        }
        $provider = new GenerationalSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $first = async(fn () => $scanner->refreshProject($this->project));
        delay(0);
        $second = async(fn () => $scanner->refreshProject($this->project));

        try {
            $first->await();
            self::fail('The superseded scan should have been canceled.');
        } catch (CancelledException) {
        }
        $second->await();

        self::assertCount(80, $provider->committed[$this->temporaryDirectory]);
    }

    public function testIndexAllContinuesWithOtherProjectsWhenAScanIsSuperseded(): void
    {
        $secondRoot = $this->temporaryDirectory.'-second';
        mkdir($secondRoot.'/src', 0777, true);

        try {
            $this->projects->replace([$this->project, new Project($secondRoot, 'file://'.$secondRoot, '^8.0')]);
            for ($i = 0; $i < 80; ++$i) {
                file_put_contents(\sprintf('%s/src/File%02d.php', $this->temporaryDirectory, $i), \sprintf('<?php final class File%02d {}', $i));
            }
            file_put_contents($secondRoot.'/src/Other.php', '<?php final class Other {}');
            $provider = new GenerationalSourceIndexProvider();
            $scanner = $this->scanner($provider);
            $indexAll = async(static fn () => $scanner->indexAll());
            delay(0);
            $supersede = async(fn () => $scanner->refreshProject($this->project));
            await([$indexAll, $supersede]);

            self::assertCount(80, $provider->committed[$this->temporaryDirectory]);
            self::assertCount(1, $provider->committed[$secondRoot]);
        } finally {
            (new Filesystem())->remove($secondRoot);
        }
    }

    public function testChecksCancellationBeforeWaitingForTheSourceLock(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire("source\0".$this->temporaryDirectory);
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scannerWithMutex($mutex, new DocumentStore(), $provider);
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        try {
            $scanner->refreshProject($this->project, $cancellation->getCancellation());
            self::fail('The scan should have been canceled before waiting for the lock.');
        } catch (CancelledException) {
        }

        self::assertSame(0, $provider->extractions);
        $lock->release();
    }

    public function testChecksCancellationAfterWaitingForTheSourceLock(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire("source\0".$this->temporaryDirectory);
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scannerWithMutex($mutex, new DocumentStore(), $provider);
        $cancellation = new DeferredCancellation();
        $scan = async(fn () => $scanner->refreshProject($this->project, $cancellation->getCancellation()));
        delay(0);
        $cancellation->cancel();
        $lock->release();

        try {
            $scan->await();
            self::fail('The scan should have observed the cancellation after acquiring the lock.');
        } catch (CancelledException) {
        }

        self::assertSame(0, $provider->extractions);
    }

    public function testRemovalCancelsTheActiveScanAndReleasesProjectEntries(): void
    {
        for ($i = 0; $i < 80; ++$i) {
            file_put_contents(\sprintf('%s/src/File%02d.php', $this->temporaryDirectory, $i), \sprintf('<?php final class File%02d {}', $i));
        }
        $provider = new GenerationalSourceIndexProvider();
        $scanner = $this->scanner($provider);
        $scan = async(fn () => $scanner->refreshProject($this->project));
        delay(0);

        $this->projects->replace([]);
        $scanner->removeProject($this->project);

        try {
            $scan->await();
            self::fail('The scan of the removed project should have been canceled.');
        } catch (CancelledException) {
        }

        self::assertArrayNotHasKey($this->temporaryDirectory, $provider->committed);
        $scanner->refreshUri('file://'.$this->temporaryDirectory.'/src/File00.php');
        self::assertArrayNotHasKey($this->temporaryDirectory, $provider->committed);
    }

    public function testARefreshWaitingForTheLockIgnoresARemovedProject(): void
    {
        $path = $this->temporaryDirectory.'/src/Controller.php';
        file_put_contents($path, '<?php final class Controller {}');
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire("source\0".$this->temporaryDirectory);
        $provider = new RecordingSourceIndexProvider();
        $scanner = $this->scannerWithMutex($mutex, new DocumentStore(), $provider);
        $refresh = async(static fn () => $scanner->refreshUri('file://'.$path));
        delay(0);

        $this->projects->replace([]);
        $lock->release();
        $refresh->await();

        self::assertSame([], $provider->replacements);
        self::assertSame([], $provider->sources);
    }

    public function testAFullScanIgnoresTheRuntimeInitializerLock(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $mutex = new LocalKeyedMutex();
        $runtimeLock = $mutex->acquire("runtime\0".$this->temporaryDirectory);
        $provider = new RecordingSourceIndexProvider();

        $this->scannerWithMutex($mutex, new DocumentStore(), $provider)->indexAll();

        self::assertSame(1, $provider->extractions);
        $runtimeLock->release();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    private function rewriteCacheRecord(string $cachePath, string $relativePath, callable $mutate): void
    {
        $lines = explode("\n", rtrim((string) file_get_contents($cachePath), "\n"));
        foreach ($lines as $index => $line) {
            /** @var array<string, mixed> $record */
            $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if ($relativePath !== ($record['path'] ?? null)) {
                continue;
            }
            $lines[$index] = json_encode($mutate($record), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($cachePath, implode("\n", $lines)."\n");
    }

    private function securityIndexer(): SecuritySourceIndexer
    {
        $converter = new PositionConverter();

        return new SecuritySourceIndexer(
            new SecuritySourceIndexRegistry(),
            new SecurityExtractor(
                $converter,
                new YamlConfigurationParser(
                    $converter,
                    new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
                ),
                new TwigCommentParser(),
                new TolerantPhpParser(new Parser()),
                new PhpCommentParser(),
            ),
        );
    }

    private function scanner(SourceIndexProviderInterface ...$providers): ApplicationSourceScanner
    {
        return $this->scannerWithDocuments(new DocumentStore(), ...$providers);
    }

    private function scannerWithDocuments(DocumentStore $documents, SourceIndexProviderInterface ...$providers): ApplicationSourceScanner
    {
        return $this->scannerWithMutex(new LocalKeyedMutex(), $documents, ...$providers);
    }

    private function scannerWithMutex(LocalKeyedMutex $mutex, DocumentStore $documents, SourceIndexProviderInterface ...$providers): ApplicationSourceScanner
    {
        return new ApplicationSourceScanner(
            $this->projects,
            $documents,
            new ProjectIndexStatusRegistry(),
            new NullProgressReporter(),
            new PersistentSourceIndexStore('test', new Filesystem()),
            new SourceIndexPayloadCodec(),
            new PhpRuntimeStructureHasher(),
            new UriToPathConverter(),
            new SourceFileEnumerator(new GitignoreMatcher()),
            $mutex,
            $providers,
        );
    }
}

final class MissingPayloadData
{
}

final class ObjectFactsSourceIndexProvider implements SourceIndexProviderInterface
{
    public int $extractions = 0;
    public int $restores = 0;

    /** @var list<string> */
    public array $restoredUris = [];

    /** @param list<string> $pathsWithFacts */
    public function __construct(private readonly array $pathsWithFacts)
    {
    }

    public function name(): string
    {
        return 'objectFacts';
    }

    public function payloadClasses(): array
    {
        return [RouteDeclaration::class, RouteSourceFacts::class];
    }

    public function begin(Project $project): void
    {
    }

    public function index(Project $project, SourceDocument $document): RouteSourceFacts
    {
        ++$this->extractions;

        return $this->extract($project, $document);
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException();
        }
        ++$this->restores;
        $this->restoredUris[] = $data->uri();
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): RouteSourceFacts
    {
        return $this->extract($project, $document);
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException();
        }

        return $data->declarations();
    }

    public function remove(Project $project, string $uri): void
    {
    }

    public function overlay(Project $project, Document $document): void
    {
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }

    private function extract(Project $project, SourceDocument $document): RouteSourceFacts
    {
        foreach ($this->pathsWithFacts as $path) {
            if ($document->uri() === $project->rootUri().'/'.$path) {
                $range = new Range(new Position(0, 0), new Position(0, 1));

                return new RouteSourceFacts($document->uri(), [new RouteDeclaration($path, $document->uri(), $range)], []);
            }
        }

        return new RouteSourceFacts($document->uri(), [], []);
    }
}

final class RecordingSourceIndexProvider implements SourceIndexProviderInterface
{
    public int $extractions = 0;
    public int $restores = 0;

    /** @var list<string> */
    public array $replacements = [];

    /** @var list<string> */
    public array $removals = [];

    /** @var list<string> */
    public array $overlays = [];

    /** @var array<string, string> */
    public array $sources = [];

    /** @param list<string>|null $payloadClasses */
    public function __construct(private readonly string $name = 'recording', private readonly ?array $payloadClasses = null)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function payloadClasses(): array
    {
        return $this->payloadClasses ?? [RouteDeclaration::class, RouteSourceFacts::class];
    }

    public function begin(Project $project): void
    {
        $this->sources = [];
    }

    public function index(Project $project, SourceDocument $document): RouteSourceFacts
    {
        ++$this->extractions;

        return $this->record($document);
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof RouteSourceFacts || 1 !== \count($data->declarations())) {
            throw new \UnexpectedValueException();
        }
        ++$this->restores;
        $this->sources[$data->uri()] = $data->declarations()[0]->name();
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $this->replacements[] = $document->uri();

        return $this->record($document);
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException();
        }

        return $data->declarations();
    }

    public function remove(Project $project, string $uri): void
    {
        $this->removals[] = $uri;
        unset($this->sources[$uri]);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->overlays[] = $document->uri();
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }

    private function record(SourceDocument $document): RouteSourceFacts
    {
        $hash = hash('sha256', $document->text());
        $this->sources[$document->uri()] = $hash;
        $range = new Range(new Position(0, 0), new Position(0, 0));

        return new RouteSourceFacts($document->uri(), [new RouteDeclaration($hash, $document->uri(), $range)], []);
    }
}

final class GenerationalSourceIndexProvider implements SourceIndexProviderInterface
{
    /** @var array<string, array<string, string>> */
    public array $staged = [];

    /** @var array<string, array<string, string>> */
    public array $committed = [];

    public function name(): string
    {
        return 'generational';
    }

    public function payloadClasses(): array
    {
        return [RouteDeclaration::class, RouteSourceFacts::class];
    }

    public function begin(Project $project): void
    {
        $this->staged[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $hash = hash('sha256', $document->text());
        $this->staged[$project->rootPath()][$document->uri()] = $hash;

        return $this->facts($document->uri(), $hash);
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof RouteSourceFacts || 1 !== \count($data->declarations())) {
            throw new \UnexpectedValueException();
        }
        $this->staged[$project->rootPath()][$data->uri()] = $data->declarations()[0]->name();
    }

    public function finish(Project $project): void
    {
        $root = $project->rootPath();
        $this->committed[$root] = $this->staged[$root] ?? [];
        unset($this->staged[$root]);
    }

    public function replace(Project $project, SourceDocument $document): RouteSourceFacts
    {
        $hash = hash('sha256', $document->text());
        $this->committed[$project->rootPath()][$document->uri()] = $hash;

        return $this->facts($document->uri(), $hash);
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof RouteSourceFacts) {
            throw new \UnexpectedValueException();
        }

        return $data->declarations();
    }

    public function remove(Project $project, string $uri): void
    {
        unset($this->committed[$project->rootPath()][$uri]);
    }

    public function overlay(Project $project, Document $document): void
    {
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }

    private function facts(string $uri, string $hash): RouteSourceFacts
    {
        return new RouteSourceFacts($uri, [new RouteDeclaration($hash, $uri, new Range(new Position(0, 0), new Position(0, 0)))], []);
    }
}
