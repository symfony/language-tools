<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
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
                'name' => 'App\\Controller\\ArticleController::show',
                'path' => '/article/{id}',
                'methods' => ['GET', 'HEAD'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\ArticleController::show',
                'defaults' => ['_controller'],
                'requirements' => ['id' => '\\d+'],
                'canonical' => null,
                'alias' => 'article_show',
            ],
            [
                'name' => 'article_legacy',
                'path' => '/article/{id}',
                'methods' => ['GET', 'HEAD'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\ArticleController::show',
                'defaults' => ['_controller'],
                'requirements' => ['id' => '\\d+'],
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
            [
                'name' => 'localized_legacy.en',
                'path' => '/en',
                'methods' => ['GET'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\HomeController',
                'defaults' => ['_locale', '_canonical_route', '_controller'],
                'requirements' => [],
                'canonical' => 'localized_legacy',
                'alias' => 'localized_home.en',
            ],
            [
                'name' => 'localized_legacy.fr',
                'path' => '/fr',
                'methods' => ['GET'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\HomeController',
                'defaults' => ['_locale', '_canonical_route', '_controller'],
                'requirements' => [],
                'canonical' => 'localized_legacy',
                'alias' => 'localized_home.fr',
            ],
        ], $result['sections']['routes']['items']);
        self::assertSame(['_locale', 'tenant'], $result['sections']['routes']['contextParameters']);
        self::assertSame(['config/endpoints/LegacyEndpoints.php', 'config/http_endpoints.yaml'], $result['sections']['routes']['resources']);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    #[DataProvider('localizedAliasVersionProvider')]
    public function testExposesCanonicalAliasesOnlyOnSupportedRoutingVersions(string $version, bool $supported): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication(version: $version);

        $process = $this->bridge->run(['--sections=routes']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        $sections = $result['sections'] ?? null;
        self::assertIsArray($sections);
        $routes = $sections['routes'] ?? null;
        self::assertIsArray($routes);
        $items = $routes['items'] ?? null;
        self::assertIsArray($items);
        $aliases = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => \is_array($item)
                && \is_string($item['name'] ?? null)
                && str_starts_with($item['name'], 'localized_legacy.'),
        ));
        self::assertSame(['localized_legacy.en', 'localized_legacy.fr'], array_column($aliases, 'name'));
        self::assertSame($supported ? ['localized_legacy', 'localized_legacy'] : [null, null], array_column($aliases, 'canonical'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function localizedAliasVersionProvider(): iterable
    {
        yield 'older LTS' => ['6.4.45', false];
        yield 'initial LTS minor' => ['7.4.0', false];
        yield 'before the LTS fix' => ['7.4.5', false];
        yield 'first LTS fix' => ['7.4.6', true];
        yield 'initial major' => ['8.0.0', false];
        yield 'before the major fix' => ['8.0.5', false];
        yield 'first major fix' => ['8.0.6', true];
        yield 'prefixed version' => ['v8.0.6', true];
        yield 'next minor' => ['8.1.0', true];
        yield 'development minor' => ['8.2.x-dev', true];
    }
}
