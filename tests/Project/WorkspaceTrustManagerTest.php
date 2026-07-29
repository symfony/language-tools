<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;

final class WorkspaceTrustManagerTest extends TestCase
{
    public function testUsesClientProvidedTrustWithoutPrompting(): void
    {
        $client = new CapturingClient(null);
        $trust = new WorkspaceTrust();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust);

        $manager->applyInitializationOptions([
            'initializationOptions' => ['workspaceTrust' => true],
        ], $registry);
        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame([], $client->requests);
    }

    public function testPromptsForUnknownTrustAndEnablesRuntimeIndexing(): void
    {
        $client = new CapturingClient(['title' => 'Trust and enable runtime indexing']);
        $trust = new WorkspaceTrust();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager($client, $trust);

        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Trusted, $trust->status($project));
        self::assertSame('window/showMessageRequest', $client->requests[0]['method']);
        self::assertSame(
            'Symfony LSP must execute application code to index runtime metadata for "/workspace".',
            $client->requests[0]['params']['message'],
        );
    }

    public function testKeepsStaticOnlyModeWhenTrustIsDeclined(): void
    {
        $trust = new WorkspaceTrust();
        $registry = $this->registry($project = new Project('/workspace', 'file:///workspace', '^8.0'));
        $manager = new WorkspaceTrustManager(new CapturingClient(null), $trust);

        $manager->requestUnknownDecisions($registry);

        self::assertSame(TrustStatus::Untrusted, $trust->status($project));
    }

    private function registry(Project $project): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace([$project]);

        return $registry;
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
}
