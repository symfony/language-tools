<?php

namespace Symfony\Lsp\Tests\Project;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;

final class WorkspaceTrustManagerTest extends TestCase
{
    public function testUsesClientProvidedTrustWithoutPrompting(): void
    {
        $client = new CapturingClient(null);
        $trust = new WorkspaceTrust();
        $runtimeInitializer = new CapturingRuntimeInitializer();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer);

        $manager->applyInitializationOptions([
            'initializationOptions' => ['workspaceTrust' => true],
        ], $registry);
        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame([], $client->requests);
        self::assertSame(['/workspace'], $runtimeInitializer->projects);
    }

    public function testPromptsForUnknownTrustAndEnablesRuntimeIndexing(): void
    {
        $client = new CapturingClient(['title' => 'Trust and enable runtime indexing']);
        $trust = new WorkspaceTrust();
        $runtimeInitializer = new CapturingRuntimeInitializer();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust, $runtimeInitializer);

        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame('window/showMessageRequest', $client->requests[0]['method']);
        self::assertSame(
            'Symfony LSP must execute application code to index runtime metadata for "/workspace".',
            $client->requests[0]['params']['message'],
        );
        self::assertSame(['/workspace'], $runtimeInitializer->projects);
    }

    public function testKeepsStaticOnlyModeWhenTrustIsDeclined(): void
    {
        $trust = new WorkspaceTrust();
        $runtimeInitializer = new CapturingRuntimeInitializer();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust, $runtimeInitializer);

        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Untrusted, $trust->status($project));
        self::assertSame([], $runtimeInitializer->projects);
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

    public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath();
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
