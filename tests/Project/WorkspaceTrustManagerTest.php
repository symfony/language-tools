<?php

namespace Symfony\Lsp\Tests\Project;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

final class WorkspaceTrustManagerTest extends TestCase
{
    public function testUsesClientProvidedTrustWithoutPrompting(): void
    {
        $client = new CapturingClient(null);
        $trust = new WorkspaceTrust();
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->applyInitializationOptions([
            'initializationOptions' => ['workspaceTrust' => true],
        ], $registry->all());
        $manager->requestUnknownDecisions($registry->all());

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame([], $client->requests);
        self::assertSame(['/workspace'], $runtimeInitializer->projects);
    }

    public function testPromptsForUnknownTrustAndEnablesRuntimeIndexing(): void
    {
        $client = new CapturingClient(['title' => 'Trust and enable runtime indexing']);
        $trust = new WorkspaceTrust();
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->requestUnknownDecisions($registry->all());

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame('window/showMessageRequest', $client->requests[0]['method']);
        self::assertSame(
            'Symfony Language Tools must execute application code to index runtime metadata for "/workspace".',
            $client->requests[0]['params']['message'],
        );
        self::assertSame(['/workspace'], $runtimeInitializer->projects);
    }

    public function testKeepsStaticOnlyModeWhenTrustIsDeclined(): void
    {
        $trust = new WorkspaceTrust();
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->requestUnknownDecisions($registry->all());

        self::assertSame(TrustStatus::Untrusted, $trust->status($project));
        self::assertSame([], $runtimeInitializer->projects);
    }

    public function testRetriesFailedRuntimeInitialization(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $registry = $this->registry($project);
        $trust = new WorkspaceTrust();
        $trust->set($project, TrustStatus::Trusted);
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses, [false, true]);
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->requestUnknownDecisions($registry->all());
        $manager->requestUnknownDecisions($registry->all());

        self::assertSame(['/workspace', '/workspace'], $runtimeInitializer->projects);
        self::assertSame('ready', $statuses->status($project)['runtime']['state']);
    }

    public function testDoesNotRestartInitializedRuntimeWhileARefreshIsPending(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $trust = new WorkspaceTrust();
        $trust->set($project, TrustStatus::Trusted);
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $this->registry($project));

        $manager->requestUnknownDecisions([$project]);
        $statuses->runtimeStale($project);
        $manager->requestUnknownDecisions([$project]);

        self::assertSame(['/workspace'], $runtimeInitializer->projects);
    }

    public function testRestartsRuntimeAfterConfigurationChangesOrProjectRemoval(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $trust = new WorkspaceTrust();
        $trust->set($project, TrustStatus::Trusted);
        $statuses = new ProjectIndexStatusRegistry();
        $configuration = new RuntimeConfiguration();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust, $runtimeInitializer, $statuses, $configuration, $this->registry($project));

        $manager->requestUnknownDecisions([$project]);
        $manager->requestUnknownDecisions([$project]);
        $configuration->setEnvironment($project, 'test');
        $manager->requestUnknownDecisions([$project]);
        $replacement = new Project('/workspace', 'file:///workspace', '^8.0');
        $manager->requestUnknownDecisions([$replacement]);
        $manager->removeProject($project);
        $manager->requestUnknownDecisions([$replacement]);

        self::assertSame(['/workspace', '/workspace', '/workspace'], $runtimeInitializer->projects);
    }

    public function testDiscardsDecisionsForProjectsRemovedWhileTheClientDecides(): void
    {
        $trust = new WorkspaceTrust();
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $client = new RemovingClient($registry, ['title' => 'Trust and enable runtime indexing']);
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->requestUnknownDecisions([$project]);

        self::assertSame(TrustStatus::Unknown, $trust->status($project));
        self::assertSame([], $runtimeInitializer->projects);
    }

    public function testRemovalResetsTrustSoReAddingPromptsAgain(): void
    {
        $client = new CapturingClient(['title' => 'Trust and enable runtime indexing']);
        $trust = new WorkspaceTrust();
        $statuses = new ProjectIndexStatusRegistry();
        $runtimeInitializer = new CapturingRuntimeInitializer($statuses);
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer, $statuses, new RuntimeConfiguration(), $registry);

        $manager->requestUnknownDecisions($registry->all());
        $trust->removeProject($project);
        $manager->removeProject($project);
        $manager->requestUnknownDecisions($registry->all());

        self::assertCount(2, $client->requests);
        self::assertSame(['/workspace', '/workspace'], $runtimeInitializer->projects);
    }

    private function registry(Project $project): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace([$project]);

        return $registry;
    }
}

final class CapturingRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @param list<bool> $results */
    public function __construct(
        private readonly ProjectIndexStatusRegistry $statuses,
        private array $results = [],
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath();
        if (false === (array_shift($this->results) ?? true)) {
            $this->statuses->runtimeFailed($project);
        } else {
            $this->statuses->runtimeReady($project);
        }
    }
}

final class RemovingClient implements ClientInterface
{
    public function __construct(
        private readonly ProjectRegistry $registry,
        private readonly mixed $response,
    ) {
    }

    public function request(string $method, array $params): mixed
    {
        $this->registry->replace([]);

        return $this->response;
    }

    public function notify(string $method, array $params): void
    {
    }
}

final class CapturingClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    public function __construct(
        private readonly mixed $response,
    ) {
    }

    public function request(string $method, array $params): mixed
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return $this->response;
    }

    public function notify(string $method, array $params): void
    {
    }
}
