<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\RouteFixtureBuilder;

final class BridgeRoutesTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testNormalizesStructuredRouteOutput(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        $this->workspace->write('config/http_endpoints.yaml', 'routes');
        $this->workspace->write('config/endpoints/LegacyEndpoints.php', "<?php\nnamespace App\\Endpoint;\nfinal class LegacyEndpoints {}\n");
        $this->workspace->write('var/cache/container.php', '<?php');

        $process = $this->bridge->run(['--sections=routes', '--targeted-refresh=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertSame([
            [
                'name' => 'article_legacy',
                'path' => null,
                'methods' => [],
                'schemes' => [],
                'host' => null,
                'controller' => null,
                'defaults' => [],
                'requirements' => [],
                'canonical' => null,
                'alias' => 'article_show',
            ],
            [
                'name' => 'article_show',
                'path' => '/article/{id}',
                'methods' => ['GET', 'HEAD'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\ArticleController::show',
                'defaults' => ['_controller'],
                'requirements' => ['id' => '\\d+'],
                'canonical' => null,
                'alias' => null,
            ],
            [
                'name' => 'homepage',
                'path' => '/',
                'methods' => [],
                'schemes' => ['https'],
                'host' => 'example.com',
                'controller' => null,
                'defaults' => [],
                'requirements' => [],
                'canonical' => null,
                'alias' => null,
            ],
            [
                'name' => 'localized_home.en',
                'path' => '/en',
                'methods' => ['GET'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\HomeController',
                'defaults' => ['_locale', '_canonical_route', '_controller'],
                'requirements' => [],
                'canonical' => 'localized_home',
                'alias' => null,
            ],
            [
                'name' => 'localized_home.fr',
                'path' => '/fr',
                'methods' => ['GET'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\HomeController',
                'defaults' => ['_locale', '_canonical_route', '_controller'],
                'requirements' => [],
                'canonical' => 'localized_home',
                'alias' => null,
            ],
        ], $result['sections']['routes']['items']);
        self::assertSame(['config/endpoints/LegacyEndpoints.php', 'config/http_endpoints.yaml'], $result['sections']['routes']['resources']);
        self::assertTrue($result['sections']['routes']['complete']);
    }
}
