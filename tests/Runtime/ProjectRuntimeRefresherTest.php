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
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\RuntimeRefreshPlanner;
use Symfony\Lsp\Runtime\RuntimeRefreshSchedulerInterface;

final class ProjectRuntimeRefresherTest extends TestCase
{
    /**
     * @param list<string>      $domains
     * @param list<string>|null $sections
     */
    #[DataProvider('resourceProvider')]
    public function testPlansRefreshesFromChangedSourceDomains(string $uri, array $domains, RuntimeRefreshMode $mode, ?array $sections, bool $preservesContainer): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]], SourceFileChange::factsChanged($domains));

        self::assertCount(1, $scheduler->plans);
        self::assertSame($mode, $scheduler->plans[0]->mode());
        self::assertSame($sections, $scheduler->plans[0]->sections());
        self::assertSame($preservesContainer, $scheduler->plans[0]->preservesContainer());
    }

    /** @return iterable<string, array{string, list<string>, RuntimeRefreshMode, list<string>|null, bool}> */
    public static function resourceProvider(): iterable
    {
        yield 'route attribute' => ['file:///workspace/src/Controller.php', ['routes'], RuntimeRefreshMode::Reuse, ['routes'], true];
        yield 'route YAML' => ['file:///workspace/config/routes.yaml', ['routes'], RuntimeRefreshMode::Reuse, ['routes'], true];
        yield 'asset and Stimulus' => ['file:///workspace/assets/app.js', ['assets', 'stimulus'], RuntimeRefreshMode::Reuse, ['assets', 'stimulus'], true];
        yield 'event' => ['file:///workspace/src/Listener.php', ['events'], RuntimeRefreshMode::Clear, ['events', 'container'], false];
        yield 'translation' => ['file:///workspace/translations/messages.en.yaml', ['translations'], RuntimeRefreshMode::Reuse, ['translations'], true];
        yield 'ambiguous configuration' => ['file:///workspace/config/packages/framework.yaml', ['dependencyInjection'], RuntimeRefreshMode::Clear, null, false];
        yield 'unknown domain' => ['file:///workspace/src/Entity.php', ['doctrine_v1'], RuntimeRefreshMode::Clear, null, false];
    }

    public function testPlansCreatedAndDeletedIndependentResourcesFromTheirPaths(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/assets/new_controller.js'],
        ], SourceFileChange::untracked());

        self::assertSame(['assets', 'stimulus'], $scheduler->plans[0]->sections());
        self::assertTrue($scheduler->plans[0]->preservesContainer());
    }

    public function testDoesNotRefreshUntrustedProjects(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Untrusted);

        $refresher->refreshAfterSave([
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php'],
        ], SourceFileChange::factsChanged(['routes']));

        self::assertSame([], $scheduler->plans);
    }

    #[DataProvider('ignoredResourceProvider')]
    public function testDoesNotRefreshUnrelatedOrGeneratedResources(string $uri): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);

        $refresher->refreshAfterSave(['textDocument' => ['uri' => $uri]], SourceFileChange::factsChanged(['routes']));

        self::assertSame([], $scheduler->plans);
    }

    public function testDoesNotRefreshWhenRuntimeStructureIsUnchanged(): void
    {
        [$refresher, $scheduler] = $this->refresher(TrustStatus::Trusted);
        $params = ['textDocument' => ['uri' => 'file:///workspace/src/Service.php']];

        $refresher->refreshAfterSave($params, SourceFileChange::contentOnly());
        $refresher->refreshAfterSave($params, SourceFileChange::unchanged());

        self::assertSame([], $scheduler->plans);
    }

    /** @return iterable<string, array{string}> */
    public static function ignoredResourceProvider(): iterable
    {
        yield 'template' => ['file:///workspace/templates/article.html.twig'];
        yield 'cache' => ['file:///workspace/var/cache/test/Container.php'];
        yield 'dependency' => ['file:///workspace/vendor/acme/example-bundle/Extension.php'];
    }

    /** @return array{ProjectRuntimeRefresher, RefreshScheduler} */
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
                new RuntimeRefreshPlanner(),
            ),
            $scheduler,
        ];
    }
}

final class RefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];

    public function schedule(Project $project, ?RuntimeRefreshPlan $plan = null): void
    {
        $this->plans[] = $plan ?? new RuntimeRefreshPlan(RuntimeRefreshMode::Clear);
    }
}
