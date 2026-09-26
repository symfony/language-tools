<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectDocumentReaderTest extends TestCase
{
    private TestWorkspace $workspace;
    private Project $project;
    private DocumentStore $documents;
    private ProjectDocumentReader $reader;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-');
        $this->workspace->mkdir('src');
        $this->project = new Project($this->workspace->rootPath, 'file://'.$this->workspace->rootPath);
        $this->documents = new DocumentStore();
        $this->reader = new ProjectDocumentReader($this->documents, ProjectPaths::resolver());
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testPrefersTheOpenDocumentTextAndVersionOverDiskContents(): void
    {
        $path = $this->workspace->path('src/Service.php');
        file_put_contents($path, '<?php // saved');
        $uri = 'file://'.$path;
        $this->documents->open(new Document($uri, 'php', 4, '<?php // unsaved'));

        $document = $this->reader->read($this->project, $uri);

        self::assertSame('<?php // unsaved', $document?->text);
        self::assertSame(4, $document->version);
    }

    public function testReadsClosedDocumentsFromDiskWithoutAVersion(): void
    {
        $path = $this->workspace->path('src/Service.php');
        file_put_contents($path, '<?php // saved');

        $document = $this->reader->read($this->project, 'file://'.$path);

        self::assertSame('<?php // saved', $document?->text);
        self::assertNull($document->version);
    }

    public function testRejectsDependencyOwnedFilesEvenWhenOpen(): void
    {
        $this->workspace->mkdir('vendor/acme');
        $path = $this->workspace->path('vendor/acme/Service.php');
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
        self::assertNull($this->reader->read($this->project, 'file://'.$this->workspace->path('src/Missing.php')));

        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            return;
        }
        $path = $this->workspace->path('src/Unreadable.php');
        file_put_contents($path, '<?php');
        chmod($path, 0000);
        try {
            self::assertNull($this->reader->read($this->project, 'file://'.$path));
        } finally {
            chmod($path, 0644);
        }
    }
}
