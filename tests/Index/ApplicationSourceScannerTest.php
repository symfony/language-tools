<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceIndexer;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFileChange;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\NullProgressReporter;

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

    public function testRestoresPersistentFactsAndRebuildsCorruptedEntries(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', '<?php final class Controller {}');
        $firstProvider = new RecordingSourceIndexProvider();
        $this->scanner($firstProvider)->indexAll();

        self::assertSame(1, $firstProvider->extractions);
        self::assertSame(0, $firstProvider->restores);

        $cachePath = $this->temporaryDirectory.'/var/symfony-lsp/test/index/source.json';
        /** @var array{entries: array<string, array{runtimeStructure?: ?string, providers: array<string, string>}>} $cache */
        $cache = json_decode((string) file_get_contents($cachePath), true, 512, \JSON_THROW_ON_ERROR);
        unset($cache['entries']['src/Controller.php']['runtimeStructure']);
        file_put_contents($cachePath, json_encode($cache, \JSON_THROW_ON_ERROR));

        $secondProvider = new RecordingSourceIndexProvider();
        $this->scanner($secondProvider)->indexAll();

        self::assertSame(0, $secondProvider->extractions);
        self::assertSame(1, $secondProvider->restores);

        /** @var array{entries: array<string, array{runtimeStructure?: ?string, providers: array<string, string>}>} $cache */
        $cache = json_decode((string) file_get_contents($cachePath), true, 512, \JSON_THROW_ON_ERROR);
        $cache['entries']['src/Controller.php']['providers']['recording'] = 'invalid';
        file_put_contents($cachePath, json_encode($cache, \JSON_THROW_ON_ERROR));

        $thirdProvider = new RecordingSourceIndexProvider();
        $this->scanner($thirdProvider)->indexAll();

        self::assertSame(1, $thirdProvider->extractions);
        self::assertSame(0, $thirdProvider->restores);
    }

    public function testPersistentFactsNeverContainEnvironmentValues(): void
    {
        file_put_contents($this->temporaryDirectory.'/.env', "APP_SECRET=canary-value\n");
        $indexes = new EnvironmentIndexRegistry();
        $this->scanner(new EnvironmentSourceIndexer(
            $indexes,
            new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter()),
        ))->indexAll();

        self::assertSame(['APP_SECRET'], $indexes->forProject($this->project)->names());
        $cache = (string) file_get_contents($this->temporaryDirectory.'/var/symfony-lsp/test/index/source.json');
        self::assertStringNotContainsString('canary-value', $cache);
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
        self::assertSame(SourceFileChange::ContentOnly, $scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]]));

        self::assertSame([$firstUri], $provider->replacements);
        self::assertCount(2, $provider->sources);
        self::assertSame(hash('sha256', '<?php final class FirstVersion { public function value(): int { return 2; } }'), $provider->sources[$firstUri]);

        file_put_contents($firstPath, '<?php final class NewFirstVersion { public function value(): int { return 2; } }');
        self::assertSame(SourceFileChange::FactsChanged, $scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]]));
        self::assertSame([$firstUri, $firstUri], $provider->replacements);

        touch($firstPath, time() + 1);
        self::assertSame(SourceFileChange::Unchanged, $scanner->refreshAfterSave(['textDocument' => ['uri' => $firstUri]]));
        self::assertSame([$firstUri, $firstUri], $provider->replacements);

        $secondUri = 'file://'.$secondPath;
        unlink($secondPath);
        $scanner->refreshUri($secondUri, true);

        self::assertSame([$secondUri], $provider->removals);
        self::assertSame([$firstUri => hash('sha256', '<?php final class NewFirstVersion { public function value(): int { return 2; } }')], $provider->sources);
    }

    private function scanner(SourceIndexProviderInterface $provider): ApplicationSourceScanner
    {
        return new ApplicationSourceScanner(
            $this->projects,
            new DocumentStore(),
            new ProjectIndexStatusRegistry(),
            new NullProgressReporter(),
            new PersistentSourceIndexStore('test', new Filesystem()),
            new SourceIndexPayloadCodec(),
            new PhpRuntimeStructureHasher(),
            new UriToPathConverter(),
            [$provider],
        );
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

    /** @var array<string, string> */
    public array $sources = [];

    public function name(): string
    {
        return 'recording';
    }

    public function begin(Project $project): void
    {
        $this->sources = [];
    }

    public function index(Project $project, SourceDocument $document): string
    {
        ++$this->extractions;
        $hash = hash('sha256', $document->text());
        $this->sources[$document->uri()] = $hash;

        return $hash;
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!\is_string($data)) {
            throw new \UnexpectedValueException();
        }
        ++$this->restores;
        $this->sources[$project->rootUri().'/src/Controller.php'] = $data;
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): string
    {
        $this->replacements[] = $document->uri();
        $hash = hash('sha256', $document->text());
        $this->sources[$document->uri()] = $hash;

        return $hash;
    }

    public function remove(Project $project, string $uri): void
    {
        $this->removals[] = $uri;
        unset($this->sources[$uri]);
    }

    public function overlay(Project $project, Document $document): void
    {
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }
}
