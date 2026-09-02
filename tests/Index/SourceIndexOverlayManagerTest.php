<?php

namespace Symfony\Lsp\Tests\Index;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\PhpParseHealthResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Index\SourceIndexOverlayManager;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
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
            $health = new SourceOverlayHealthRegistry();
            $manager = new SourceIndexOverlayManager(
                $projects,
                $documents,
                new UriToPathConverter(),
                new SourceFileEnumerator(new GitignoreMatcher(), new ProjectFileScopeRegistry(new GlobPatternCompiler())),
                $pipeline,
                new PhpParseHealthResolver(new TolerantPhpParser(new Parser()), $health),
                $health,
            );

            $manager->reapply($project);
            $manager->removeUri($sourceUri);

            self::assertSame([$sourceUri], $provider->overlays);
            self::assertSame([SourceParseHealth::Healthy], $provider->healths);
            self::assertSame([$vendorUri, $sourceUri], $provider->removals);
            self::assertSame($project, $manager->locateUri($sourceUri)?->project);
            self::assertNull($manager->locateUri($vendorUri));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testTracksPartialPhpHealthAndClearsItOnExclusionAndClose(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-overlay-health-'.bin2hex(random_bytes(8));
        mkdir($root.'/src', 0777, true);

        try {
            $projects = new ProjectRegistry();
            $projects->replace([$project = new Project($root, 'file://'.$root)]);
            $documents = new DocumentStore();
            $uri = 'file://'.$root.'/src/Service.php';
            $documents->open(new Document($uri, 'php', 1, '<?php final class Service { public function run('));
            $provider = new OverlayRecordingProvider();
            $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [$provider]);
            $scope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
            $files = new SourceFileEnumerator(new GitignoreMatcher(), $scope);
            $health = new SourceOverlayHealthRegistry();
            $parser = new CountingPhpParser(new TolerantPhpParser(new Parser()));
            $manager = new SourceIndexOverlayManager(
                $projects,
                $documents,
                new UriToPathConverter(),
                $files,
                $pipeline,
                new PhpParseHealthResolver($parser, $health),
                $health,
            );

            $manager->updateUri($uri);
            self::assertSame(1, $parser->calls);
            self::assertSame([SourceParseHealth::Partial], $provider->healths);
            self::assertTrue($health->isDegraded($uri));

            $manager->updateUri($uri, trackParseHealth: false);
            self::assertSame(1, $parser->calls);
            self::assertSame([SourceParseHealth::Partial, SourceParseHealth::Healthy], $provider->healths);
            self::assertFalse($health->isDegraded($uri));

            $manager->updateUri($uri);
            self::assertSame(2, $parser->calls);
            self::assertTrue($health->isDegraded($uri));

            $scope->configure($project, ['src/**']);
            $manager->updateUri($uri);
            self::assertSame(2, $parser->calls);
            self::assertFalse($health->isDegraded($uri));

            $scope->configure($project, []);
            $manager->updateUri($uri);
            self::assertSame(3, $parser->calls);
            self::assertTrue($health->isDegraded($uri));

            $manager->removeUri($uri);
            self::assertFalse($health->isDegraded($uri));
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

    /** @var list<SourceParseHealth> */
    public array $healths = [];

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

    public function overlay(Project $project, Document $document, SourceParseHealth $health): void
    {
        $this->overlays[] = $document->uri;
        $this->healths[] = $health;
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->removals[] = $uri;
    }
}

final class CountingPhpParser implements PhpParserInterface
{
    public int $calls = 0;

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function parse(string $source): PhpDocument
    {
        ++$this->calls;

        return $this->parser->parse($source);
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
