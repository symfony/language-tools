<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Index\PersistentSourceIndexStore;
use Symfony\Lsp\Index\SourceIndexJsonLinesCodec;
use Symfony\Lsp\Index\SourceIndexStoreInterface;
use Symfony\Lsp\Project\Project;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexStoreTest extends TestCase
{
    private string $temporaryDirectory;
    private Project $project;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-store-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
        $this->project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testRewriteRoundTripsMetadataAndPayloads(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload-a', 'events' => 'payload-b']);
        $writer->add('src/B.php', $this->metadata(2), ['routes' => 'payload-c']);
        $writer->commit();

        $fresh = $this->store();
        self::assertSame(['src/A.php', 'src/B.php'], array_keys($fresh->loadMetadata($this->project)));
        self::assertSame(['routes' => 'payload-a', 'events' => 'payload-b'], $fresh->loadPayloads($this->project, 'src/A.php'));
        self::assertSame(['routes' => 'payload-c'], $fresh->loadPayloads($this->project, 'src/B.php'));
        self::assertSame([], $fresh->loadPayloads($this->project, 'src/Missing.php'));
    }

    public function testSequentialReaderReturnsOnlyLatestRecords(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'stale']);
        $writer->add('src/B.php', $this->metadata(2), ['routes' => 'deleted']);
        $writer->commit();
        $store->append($this->project, 'src/A.php', $this->metadata(3), ['routes' => 'current']);
        $store->appendDeletion($this->project, 'src/B.php');

        $reader = $store->beginRead($this->project);
        $records = iterator_to_array($reader->records());
        $reader->close();

        self::assertSame(['src/A.php'], array_keys($records));
        self::assertSame(3, $records['src/A.php']['metadata']['size']);
        self::assertSame(['routes' => 'current'], $records['src/A.php']['payloads']);
        self::assertSame(['routes' => 'current'], $store->loadPayloads($this->project, 'src/A.php'));
    }

    public function testLastAppendedRecordWins(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'stale']);
        $writer->commit();

        $store->append($this->project, 'src/A.php', $this->metadata(3), ['routes' => 'current']);

        self::assertSame(3, $store->loadMetadata($this->project)['src/A.php']['size'] ?? null);
        self::assertSame(['routes' => 'current'], $store->loadPayloads($this->project, 'src/A.php'));

        $fresh = $this->store();
        self::assertSame(3, $fresh->loadMetadata($this->project)['src/A.php']['size'] ?? null);
        self::assertSame(['routes' => 'current'], $fresh->loadPayloads($this->project, 'src/A.php'));
    }

    public function testDeletionsRemoveEntries(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload']);
        $writer->commit();

        $store->appendDeletion($this->project, 'src/A.php');

        self::assertSame([], $store->loadPayloads($this->project, 'src/A.php'));
        self::assertSame([], $this->store()->loadMetadata($this->project));
    }

    public function testIgnoresTornTrailingRecords(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload']);
        $writer->commit();
        $path = $store->path($this->project);
        file_put_contents($path, '{"path":"src/B.php","truncated', \FILE_APPEND);

        $fresh = $this->store();

        self::assertSame(['src/A.php'], array_keys($fresh->loadMetadata($this->project)));
        self::assertSame(['routes' => 'payload'], $fresh->loadPayloads($this->project, 'src/A.php'));
    }

    public function testAppendsAfterATornTailRemainReadableAfterRestart(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload-a']);
        $writer->commit();
        file_put_contents($store->path($this->project), '{"path":"src/Torn.php"', \FILE_APPEND);

        $fresh = $this->store();
        $fresh->append($this->project, 'src/B.php', $this->metadata(2), ['routes' => 'payload-b']);

        $restarted = $this->store();
        self::assertSame(['src/A.php', 'src/B.php'], array_keys($restarted->loadMetadata($this->project)));
        self::assertSame(['routes' => 'payload-a'], $restarted->loadPayloads($this->project, 'src/A.php'));
        self::assertSame(['routes' => 'payload-b'], $restarted->loadPayloads($this->project, 'src/B.php'));
    }

    public function testDiscardsCachesFromOlderSchemaVersions(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload']);
        $writer->commit();
        $path = $store->path($this->project);
        $contents = (string) file_get_contents($path);
        /** @var array{schemaVersion: int, serverVersion: string} $header */
        $header = json_decode((new SourceIndexJsonLinesCodec('test'))->encodeHeader(), true, 512, \JSON_THROW_ON_ERROR);
        --$header['schemaVersion'];
        file_put_contents(
            $path,
            json_encode($header, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n".substr($contents, (int) strpos($contents, "\n") + 1),
        );

        self::assertSame([], $this->store()->loadMetadata($this->project));
    }

    public function testDiscardsCachesFromOtherServerVersions(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload']);
        $writer->commit();

        $other = new PersistentSourceIndexStore('other', new Filesystem(), new SourceIndexJsonLinesCodec('other'));
        (new Filesystem())->mkdir(\dirname($other->path($this->project)));
        copy($store->path($this->project), $other->path($this->project));

        self::assertSame([], $other->loadMetadata($this->project));

        $other->append($this->project, 'src/B.php', $this->metadata(2), ['routes' => 'fresh']);

        self::assertSame(['src/B.php'], array_keys($other->loadMetadata($this->project)));
        self::assertSame(['routes' => 'fresh'], $other->loadPayloads($this->project, 'src/B.php'));
    }

    public function testAbortLeavesThePreviousGenerationInPlace(): void
    {
        $store = $this->store();
        $writer = $store->beginRewrite($this->project);
        $writer->add('src/A.php', $this->metadata(1), ['routes' => 'payload']);
        $writer->commit();

        $aborted = $store->beginRewrite($this->project);
        $aborted->add('src/B.php', $this->metadata(2), ['routes' => 'discarded']);
        $aborted->abort();

        self::assertSame(['src/A.php'], array_keys($this->store()->loadMetadata($this->project)));
        self::assertFileDoesNotExist($store->path($this->project).'.tmp');
    }

    public function testAppendStartsAFreshCacheWhenNoneExists(): void
    {
        $store = $this->store();

        $store->append($this->project, 'src/A.php', $this->metadata(1), ['routes' => 'payload']);

        self::assertSame(['src/A.php'], array_keys($this->store()->loadMetadata($this->project)));
        self::assertSame(['routes' => 'payload'], $this->store()->loadPayloads($this->project, 'src/A.php'));
    }

    private function store(): PersistentSourceIndexStore
    {
        return new PersistentSourceIndexStore('test', new Filesystem(), new SourceIndexJsonLinesCodec('test'));
    }

    /**
     * @return SourceIndexMetadata
     */
    private function metadata(int $size): array
    {
        return [
            'size' => $size,
            'modifiedAt' => 1_700_000_000 + $size,
            'hash' => str_repeat('a', 64),
            'languageId' => 'php',
            'runtimeStructure' => null,
        ];
    }
}
