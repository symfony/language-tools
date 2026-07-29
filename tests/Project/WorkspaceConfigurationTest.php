<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Project\WorkspaceTrustManager;

final class WorkspaceConfigurationTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/composer.json');
        @rmdir($this->temporaryDirectory);
    }

    public function testUsesRootUriWhenWorkspaceFoldersAreAbsent(): void
    {
        $registry = new ProjectRegistry();
        $configuration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            $registry,
            new WorkspaceTrustManager($this->client(), new WorkspaceTrust()),
        );

        $configuration->initialize(['rootUri' => 'file://'.$this->temporaryDirectory]);

        self::assertCount(1, $registry->all());
        self::assertSame('^8.0', $registry->all()[0]->frameworkBundleConstraint());
    }

    private function client(): ClientInterface
    {
        return new class implements ClientInterface {
            public function request(string $method, array $params): mixed
            {
                return null;
            }
        };
    }
}
