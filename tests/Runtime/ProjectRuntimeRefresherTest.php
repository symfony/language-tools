<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFileChange;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshSchedulerInterface;

final class ProjectRuntimeRefresherTest extends TestCase
{
    #[DataProvider('routeResourceProvider')]
    public function testRefreshesTrustedProjectsAfterRouteResourceSaves(string $uri, RuntimeRefreshMode $expectedMode): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]], SourceFileChange::Changed);

        self::assertSame(['/workspace'], $scheduler->projects);
        self::assertSame([$expectedMode], $scheduler->modes);
    }

    /**
     * @return iterable<string, array{string, RuntimeRefreshMode}>
     */
    public static function routeResourceProvider(): iterable
    {
        yield 'PHP' => ['file:///workspace/src/Controller.php', RuntimeRefreshMode::Clear];
        yield 'route YAML' => ['file:///workspace/config/routes.yaml', RuntimeRefreshMode::Clear];
        yield 'package YAML' => ['file:///workspace/config/packages/framework.yaml', RuntimeRefreshMode::Clear];
        yield 'package XML' => ['file:///workspace/config/packages/framework.xml', RuntimeRefreshMode::Clear];
        yield 'bundle metadata' => ['file:///workspace/composer.json', RuntimeRefreshMode::Clear];
        yield 'nested bundle metadata' => ['file:///workspace/packages/app/composer.json', RuntimeRefreshMode::Clear];
        yield 'mapped asset' => ['file:///workspace/assets/app.js', RuntimeRefreshMode::Clear];
        yield 'translation YAML' => ['file:///workspace/translations/messages.en.yaml', RuntimeRefreshMode::Warmup];
        yield 'translation JSON' => ['file:///workspace/translations/messages.en.json', RuntimeRefreshMode::Warmup];
        yield 'translation XLIFF' => ['file:///workspace/translations/messages.en.xlf', RuntimeRefreshMode::Warmup];
    }

    public function testDoesNotRefreshUntrustedProjects(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Untrusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php'],
        ], SourceFileChange::Changed);

        self::assertSame([], $scheduler->projects);
    }

    #[DataProvider('ignoredResourceProvider')]
    public function testDoesNotRefreshUnrelatedOrGeneratedResources(string $uri): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]], SourceFileChange::Changed);

        self::assertSame([], $scheduler->projects);
    }

    public function testDoesNotRefreshAfterSavingUnchangedSource(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php'],
        ], SourceFileChange::Unchanged);

        self::assertSame([], $scheduler->projects);
    }

    /** @return iterable<string, array{string}> */
    public static function ignoredResourceProvider(): iterable
    {
        yield 'template' => ['file:///workspace/templates/article.html.twig'];
        yield 'cache' => ['file:///workspace/var/cache/test/Container.php'];
        yield 'dependency' => ['file:///workspace/vendor/acme/example-bundle/Extension.php'];
    }

    /**
     * @return array{ProjectRuntimeRefresher, RefreshScheduler}
     */
    private function refresher(TrustStatus $status): array
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, $status);
        $scheduler = new RefreshScheduler();

        return [
            new ProjectRuntimeRefresher(
                $projects,
                new ProjectPathResolver(new UriToPathConverter()),
                $workspaceTrust,
                $scheduler,
                new ProjectIndexStatusRegistry(),
                new RuntimeConfiguration(),
            ),
            $scheduler,
        ];
    }
}

final class RefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @var list<RuntimeRefreshMode> */
    public array $modes = [];

    public function schedule(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Clear): void
    {
        $this->projects[] = $project->rootPath();
        $this->modes[] = $mode;
    }
}
