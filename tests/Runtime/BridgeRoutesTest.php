<?php

namespace Symfony\Lsp\Tests\Runtime;

final class BridgeRoutesTest extends AbstractBridgeTestCase
{
    public function testNormalizesStructuredRouteOutput(): void
    {
        $this->writeRouteApplication();
        mkdir($this->temporaryDirectory.'/config/endpoints', 0777, true);
        mkdir($this->temporaryDirectory.'/var/cache', 0777, true);
        file_put_contents($this->temporaryDirectory.'/config/http_endpoints.yaml', 'routes');
        file_put_contents($this->temporaryDirectory.'/config/endpoints/LegacyEndpoints.php', "<?php\nnamespace App\\Endpoint;\nfinal class LegacyEndpoints {}\n");
        file_put_contents($this->temporaryDirectory.'/var/cache/container.php', '<?php');

        exec(\sprintf(
            '%s %s --project=%s --sections=routes --targeted-refresh=1 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
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
