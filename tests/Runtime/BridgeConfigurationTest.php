<?php

namespace Symfony\Lsp\Tests\Runtime;

final class BridgeConfigurationTest extends AbstractBridgeTestCase
{
    public function testExportsPublicBundleConfigurationTrees(): void
    {
        $this->writeConfigurationApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=configuration 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['configuration'] ?? null);
        self::assertIsArray($result['sections']['configuration']['bundles'] ?? null);
        $bundle = $result['sections']['configuration']['bundles'][0] ?? null;
        self::assertIsArray($bundle);
        self::assertIsArray($bundle['tree'] ?? null);
        self::assertSame(['alias' => 'secret'], $bundle['tree']['aliases'] ?? null);
        self::assertSame('name', $bundle['tree']['keyAttribute'] ?? null);
        self::assertIsArray($bundle['tree']['children'] ?? null);
        $child = $bundle['tree']['children'][0] ?? null;
        self::assertIsArray($child);
        self::assertSame('framework', $bundle['alias'] ?? null);
        self::assertSame('scalar', $child['type'] ?? null);
        self::assertSame('string', $child['defaultSummary'] ?? null);
    }

    public function testDoesNotExposeApplicationExceptionsInSectionWarnings(): void
    {
        $this->writeConfigurationApplication();
        $autoload = $this->temporaryDirectory.'/vendor/autoload.php';
        $contents = str_replace(
            'return [new Bundle()];',
            'return [new Bundle(), new BrokenBundle()];',
            (string) file_get_contents($autoload),
            $count,
        );
        self::assertSame(1, $count);
        file_put_contents($autoload, $contents);

        exec(\sprintf(
            '%s %s --project=%s --sections=configuration 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_CONFIGURATION_EXCEPTION', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['configuration'] ?? null);
        self::assertSame(['The App\BrokenBundle configuration tree is unavailable.'], $result['sections']['configuration']['warnings'] ?? null);
    }
}
