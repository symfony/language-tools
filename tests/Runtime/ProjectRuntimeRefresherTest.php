<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class ProjectRuntimeRefresherTest extends TestCase
{
    #[DataProvider('routeResourceProvider')]
    public function testRefreshesTrustedProjectsAfterRouteResourceSaves(string $uri): void
    {
        [$refresher, $initializer] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]]);

        self::assertSame(['/workspace'], $initializer->projects);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeResourceProvider(): iterable
    {
        yield 'PHP' => ['file:///workspace/src/Controller.php'];
        yield 'route YAML' => ['file:///workspace/config/routes.yaml'];
        yield 'package YAML' => ['file:///workspace/config/packages/framework.yaml'];
    }

    public function testDoesNotRefreshUntrustedProjects(): void
    {
        [$refresher, $initializer] = $this->refresher(TrustStatus::Untrusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php'],
        ]);

        self::assertSame([], $initializer->projects);
    }

    public function testDoesNotRefreshUnrelatedResources(): void
    {
        [$refresher, $initializer] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/templates/article.html.twig'],
        ]);

        self::assertSame([], $initializer->projects);
    }

    /**
     * @return array{ProjectRuntimeRefresher, RefreshRuntimeInitializer}
     */
    private function refresher(TrustStatus $status): array
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, $status);
        $initializer = new RefreshRuntimeInitializer();

        return [new ProjectRuntimeRefresher($projects, $workspaceTrust, $initializer), $initializer];
    }
}

final class RefreshRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function initialize(Project $project): void
    {
        $this->projects[] = $project->rootPath();
    }
}
