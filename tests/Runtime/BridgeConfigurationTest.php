<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\ConfigurationFixtureBuilder;

final class BridgeConfigurationTest extends TestCase
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

    public function testExportsPublicBundleConfigurationTrees(): void
    {
        (new ConfigurationFixtureBuilder($this->workspace))->writeConfigurationApplication();

        $process = $this->bridge->run(['--sections=configuration']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertStringNotContainsString('CANARY_SECRET_', $process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['configuration'] ?? null);
        self::assertIsArray($result['sections']['configuration']['bundles'] ?? null);
        $bundle = $result['sections']['configuration']['bundles'][0] ?? null;
        self::assertIsArray($bundle);
        self::assertIsArray($bundle['tree'] ?? null);
        self::assertSame(['alias' => 'secret'], $bundle['tree']['aliases'] ?? null);
        self::assertSame('name', $bundle['tree']['keyAttribute'] ?? null);
        self::assertTrue($bundle['tree']['normalizeKeys'] ?? false);
        self::assertIsArray($bundle['tree']['children'] ?? null);
        $child = $bundle['tree']['children'][0] ?? null;
        self::assertIsArray($child);
        self::assertSame('framework', $bundle['alias'] ?? null);
        self::assertSame('scalar', $child['type'] ?? null);
        self::assertSame('string', $child['defaultSummary'] ?? null);
        $csp = $bundle['tree']['children'][1] ?? null;
        self::assertIsArray($csp);
        self::assertFalse($csp['normalizeKeys'] ?? true);
        self::assertIsArray($csp['children'] ?? null);
        self::assertSame(['default-src'], array_column($csp['children'], 'name'));
    }

    public function testDoesNotExposeApplicationExceptionsInSectionWarnings(): void
    {
        (new ConfigurationFixtureBuilder($this->workspace))->writeConfigurationApplication();
        self::assertSame(1, $this->workspace->replace(
            'vendor/autoload.php',
            'return [new Bundle()];',
            'return [new Bundle(), new BrokenBundle()];',
        ));

        $process = $this->bridge->run(['--sections=configuration']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertStringNotContainsString('CANARY_CONFIGURATION_EXCEPTION', $process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['configuration'] ?? null);
        self::assertSame(['The App\BrokenBundle configuration tree is unavailable.'], $result['sections']['configuration']['warnings'] ?? null);
    }
}
