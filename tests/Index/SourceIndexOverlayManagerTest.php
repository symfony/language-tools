<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Index\SourceIndexOverlayManager;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class SourceIndexOverlayManagerTest extends TestCase
{
    public function testBroadcastsEligibleOverlaysAndRemovesIneligibleOnes(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-overlay-'.bin2hex(random_bytes(8));
        mkdir($root.'/src', 0777, true);
        mkdir($root.'/vendor', 0777, true);

        try {
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($root, 'file://'.$root)]);
            $documents = new DocumentStore();
            $sourceUri = 'file://'.$root.'/src/Service.php';
            $vendorUri = 'file://'.$root.'/vendor/Dependency.php';
            $documents->open(new Document($sourceUri, 'php', 1, '<?php final class Service {}'));
            $documents->open(new Document($vendorUri, 'php', 1, '<?php final class Dependency {}'));
            $provider = new OverlayRecordingProvider();
            $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [$provider]);
            $manager = new SourceIndexOverlayManager(
                $projects,
                $documents,
                new UriToPathConverter(),
                new SourceFileEnumerator(new GitignoreMatcher(), new ProjectFileScopeRegistry(new GlobPatternCompiler())),
                $pipeline,
            );

            $manager->reapply($project);
            $manager->removeUri($sourceUri);

            self::assertSame([$sourceUri], $provider->overlays);
            self::assertSame([$vendorUri, $sourceUri], $provider->removals);
            self::assertSame($project, $manager->locateUri($sourceUri)?->project);
            self::assertNull($manager->locateUri($vendorUri));
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}

final class OverlayRecordingProvider implements SourceIndexProviderInterface
{
    /** @var list<string> */
    public array $overlays = [];

    /** @var list<string> */
    public array $removals = [];

    public function name(): string
    {
        return 'overlay';
    }

    public function payloadClasses(): array
    {
        return [OverlayFacts::class];
    }

    public function begin(Project $project): void
    {
    }

    public function index(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function restore(Project $project, mixed $data): void
    {
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return [];
    }

    public function remove(Project $project, string $uri): void
    {
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->overlays[] = $document->uri;
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->removals[] = $uri;
    }
}

final class OverlayFacts implements SourceFactsInterface
{
    public function __construct(public readonly string $uri = 'file:///source.php')
    {
    }

    public function isEmpty(): bool
    {
        return true;
    }
}
