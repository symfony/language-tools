<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceIndexFileLocation;
use Symfony\Lsp\Index\SourceIndexFileProcessor;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Tests\Support\InMemorySourceIndexStore;

final class SourceIndexFileProcessorTest extends TestCase
{
    public function testSharesSourceProcessingBetweenFullAndIncrementalIndexing(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-processor-'.bin2hex(random_bytes(8));
        mkdir($root.'/src', 0777, true);
        $path = $root.'/src/Service.php';
        $project = new Project($root, 'file://'.$root);
        $store = new InMemorySourceIndexStore();
        $provider = new ProcessorRecordingProvider();
        $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [$provider]);
        $processor = new SourceIndexFileProcessor($store, $pipeline, new PhpRuntimeStructureHasher());
        $location = new SourceIndexFileLocation($project, 'file://'.$path, $path, 'src/Service.php');

        try {
            file_put_contents($path, '<?php final class Service { public function value(): int { return 1; } }');
            $processed = $processor->scan($location, 'php', null);
            self::assertNotNull($processed);
            $store->append($project, 'src/Service.php', $processed->metadata, $processed->payloads);

            file_put_contents($path, '<?php final class Service { public function value(): int { return 2; } }');
            $bodyChange = $processor->update($location, 'php', $processed->metadata, true);
            self::assertNotNull($bodyChange);
            self::assertSame([], $bodyChange->change->domains());

            file_put_contents($path, '<?php final class RenamedService { public function value(): int { return 2; } }');
            $declarationChange = $processor->update($location, 'php', $processed->metadata, true);
            self::assertNotNull($declarationChange);
            self::assertSame(['processor'], $declarationChange->change->domains());
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}

final class ProcessorRecordingProvider implements SourceIndexProviderInterface
{
    public function name(): string
    {
        return 'processor';
    }

    public function payloadClasses(): array
    {
        return [ProcessorFacts::class];
    }

    public function begin(Project $project): void
    {
    }

    public function index(Project $project, SourceDocument $document): SourceFactsInterface
    {
        return new ProcessorFacts($document->uri, hash('sha256', $document->text));
    }

    public function restore(Project $project, mixed $data): void
    {
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): SourceFactsInterface
    {
        return $this->index($project, $document);
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return $data instanceof ProcessorFacts ? [$data->value] : [];
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
}

final class ProcessorFacts implements SourceFactsInterface
{
    public function __construct(public readonly string $uri, public readonly string $value)
    {
    }

    public function isEmpty(): bool
    {
        return false;
    }
}
