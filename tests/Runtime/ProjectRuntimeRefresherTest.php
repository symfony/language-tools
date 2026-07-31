<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\RuntimeRefreshSchedulerInterface;

final class ProjectRuntimeRefresherTest extends TestCase
{
    #[DataProvider('routeResourceProvider')]
    public function testRefreshesTrustedProjectsAfterRouteResourceSaves(string $uri): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]]);

        self::assertSame(['/workspace'], $scheduler->projects);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeResourceProvider(): iterable
    {
        yield 'PHP' => ['file:///workspace/src/Controller.php'];
        yield 'route YAML' => ['file:///workspace/config/routes.yaml'];
        yield 'package YAML' => ['file:///workspace/config/packages/framework.yaml'];
        yield 'package XML' => ['file:///workspace/config/packages/framework.xml'];
        yield 'bundle metadata' => ['file:///workspace/composer.json'];
        yield 'translation YAML' => ['file:///workspace/translations/messages.en.yaml'];
        yield 'translation JSON' => ['file:///workspace/translations/messages.en.json'];
        yield 'translation XLIFF' => ['file:///workspace/translations/messages.en.xlf'];
    }

    public function testDoesNotRefreshUntrustedProjects(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Untrusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php'],
        ]);

        self::assertSame([], $scheduler->projects);
    }

    public function testDoesNotRefreshUnrelatedResources(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/templates/article.html.twig'],
        ]);

        self::assertSame([], $scheduler->projects);
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
                $workspaceTrust,
                $scheduler,
                new ProjectIndexStatusRegistry(),
            ),
            $scheduler,
        ];
    }
}

final class RefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function schedule(Project $project): void
    {
        $this->projects[] = $project->rootPath();
    }
}
