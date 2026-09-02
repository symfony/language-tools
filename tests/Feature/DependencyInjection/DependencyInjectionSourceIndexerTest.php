<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Amp\Sync\LocalKeyedMutex;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexer;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Index\SourceIndexFileProcessor;
use Symfony\Lsp\Index\SourceIndexOverlayManager;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\InMemorySourceIndexStore;
use Symfony\Lsp\Tests\Support\NullProgressReporter;

final class DependencyInjectionSourceIndexerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/config/services.yaml');
        @rmdir($this->temporaryDirectory.'/config');
        @rmdir($this->temporaryDirectory);
    }

    public function testOpenDocumentsOverlayAndRestoreDiskBackedFacts(): void
    {
        $path = $this->temporaryDirectory.'/config/services.yaml';
        file_put_contents($path, "services:\n    app.disk: ~\n");
        $uri = 'file://'.$path;
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        )]);
        $documents = new DocumentStore();
        $indexes = new DependencyInjectionSourceIndexRegistry();
        $converter = new PositionConverter();
        $store = new InMemorySourceIndexStore();
        $files = new SourceFileEnumerator(new GitignoreMatcher(), new ProjectFileScopeRegistry(new GlobPatternCompiler()));
        $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [new DependencyInjectionSourceIndexer(
            $indexes,
            new YamlDependencyInjectionExtractor(
                new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
                new YamlDependencyInjectionDeclarationExtractor($converter),
                new YamlDependencyInjectionReferenceExtractor($converter),
            ),
            new XmlDependencyInjectionExtractor($converter),
            new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser())),
            new PhpClassDeclarationExtractor($converter, new TolerantPhpParser(new Parser())),
        )]);
        $scanner = new ApplicationSourceScanner(
            $projects,
            new ProjectIndexStatusRegistry(),
            new NullProgressReporter(),
            $store,
            $files,
            new LocalKeyedMutex(),
            new ServerLogger(null, new SensitiveDataRedactor()),
            $pipeline,
            new SourceIndexFileProcessor($store, $pipeline, new PhpRuntimeStructureHasher()),
            new SourceIndexOverlayManager($projects, $documents, new UriToPathConverter(), $files, $pipeline),
        );

        $scanner->indexAll();
        self::assertSame(['app.disk'], $indexes->forProject($project)->serviceIds());

        $documents->open(new Document($uri, 'yaml', 2, "services:\n    app.overlay: ~\n"));
        $scanner->updateOpenDocument(['textDocument' => ['uri' => $uri]]);
        self::assertSame(['app.overlay'], $indexes->forProject($project)->serviceIds());

        $documents->close($uri);
        $scanner->restoreClosedDocument(['textDocument' => ['uri' => $uri]]);
        self::assertSame(['app.disk'], $indexes->forProject($project)->serviceIds());
    }
}
