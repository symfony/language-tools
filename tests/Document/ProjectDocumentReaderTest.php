<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectDocumentReaderTest extends TestCase
{
    private string $temporaryDirectory;
    private Project $project;
    private DocumentStore $documents;
    private ProjectDocumentReader $reader;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/src', 0777, true);
        $this->project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $this->documents = new DocumentStore();
        $this->reader = new ProjectDocumentReader($this->documents, new ProjectPathResolver(new UriToPathConverter()));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testPrefersTheOpenDocumentTextAndVersionOverDiskContents(): void
    {
        $path = $this->temporaryDirectory.'/src/Service.php';
        file_put_contents($path, '<?php // saved');
        $uri = 'file://'.$path;
        $this->documents->open(new Document($uri, 'php', 4, '<?php // unsaved'));

        $document = $this->reader->read($this->project, $uri);

        self::assertSame('<?php // unsaved', $document?->text);
        self::assertSame(4, $document->version);
    }

    public function testReadsClosedDocumentsFromDiskWithoutAVersion(): void
    {
        $path = $this->temporaryDirectory.'/src/Service.php';
        file_put_contents($path, '<?php // saved');

        $document = $this->reader->read($this->project, 'file://'.$path);

        self::assertSame('<?php // saved', $document?->text);
        self::assertNull($document->version);
    }

    public function testRejectsDependencyOwnedFilesEvenWhenOpen(): void
    {
        mkdir($this->temporaryDirectory.'/vendor/acme', 0777, true);
        $path = $this->temporaryDirectory.'/vendor/acme/Service.php';
        file_put_contents($path, '<?php // dependency');
        $uri = 'file://'.$path;
        $this->documents->open(new Document($uri, 'php', 1, '<?php // dependency'));

        self::assertNull($this->reader->read($this->project, $uri));
    }

    public function testRejectsUrisOutsideTheProjectRoot(): void
    {
        self::assertNull($this->reader->read($this->project, 'file:///outside/Service.php'));
    }

    public function testReturnsNullForMissingOrUnreadableFiles(): void
    {
        self::assertNull($this->reader->read($this->project, 'file://'.$this->temporaryDirectory.'/src/Missing.php'));

        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            return;
        }
        $path = $this->temporaryDirectory.'/src/Unreadable.php';
        file_put_contents($path, '<?php');
        chmod($path, 0000);
        try {
            self::assertNull($this->reader->read($this->project, 'file://'.$path));
        } finally {
            chmod($path, 0644);
        }
    }
}
