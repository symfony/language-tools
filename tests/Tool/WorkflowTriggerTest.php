<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ConfigurationLoader;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;

final class WorkflowTriggerTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    #[DataProvider('regularWorkflowProvider')]
    public function testChangelogPushesRunEveryRegularWorkflow(string $workflow): void
    {
        $contents = file_get_contents(self::ROOT.'/.github/workflows/'.$workflow);
        self::assertIsString($contents);
        if (1 !== preg_match('/^    push:\R(?<push>.*?)(?=^    workflow_dispatch:)/ms', $contents, $matches)) {
            self::fail('The workflow has no push configuration.');
        }

        $push = $matches['push'];
        if (str_contains($push, '        paths-ignore:')) {
            self::assertStringNotContainsString('            - CHANGELOG.md', $push);

            return;
        }

        self::assertStringContainsString('        paths:', $push);
        self::assertStringContainsString('            - CHANGELOG.md', $push);
    }

    public function testDogfoodProjectsConfigureTheirNonsecretEnvironmentLocally(): void
    {
        $workflow = file_get_contents(self::ROOT.'/.github/workflows/dogfood.yaml');
        self::assertIsString($workflow);
        self::assertStringNotContainsString('matrix.environment', $workflow);
        self::assertStringNotContainsString('mysql: true', $workflow);
        self::assertStringNotContainsString('Start MySQL', $workflow);
        self::assertStringNotContainsString('dogfood-mysql', $workflow);

        self::assertSame([
            'DATABASE_URL' => 'mysql://root:root@127.0.0.1:9/dogfood?serverVersion=8.0.32&charset=utf8mb4',
        ], $this->dogfoodConfiguration('sylius')->environmentVariables);

        self::assertSame([
            'APP_SECRET' => 'dogfood-not-a-secret',
            'COMPOSER_PLUGIN_LOADER' => '1',
            'DATABASE_URL' => 'mysql://shopware:shopware@127.0.0.1:9/shopware',
        ], $this->dogfoodConfiguration('shopware')->environmentVariables);
    }

    public function testReleaseBodyUsesOnlyTheCurrentChangelogSection(): void
    {
        $contents = file_get_contents(self::ROOT.'/.github/workflows/release.yaml');
        self::assertIsString($contents);
        self::assertStringContainsString('run: ./tools/release-notes "$GITHUB_REF_NAME" > RELEASE_NOTES.md', $contents);
        self::assertStringContainsString('body_path: RELEASE_NOTES.md', $contents);
        self::assertStringNotContainsString('body_path: CHANGELOG.md', $contents);
    }

    public function testOpenVsxStepsAreSkippedWithoutCredentials(): void
    {
        $contents = file_get_contents(self::ROOT.'/.github/workflows/publish-vscode.yaml');
        self::assertIsString($contents);
        self::assertStringContainsString('            - name: Detect Open VSX credentials', $contents);
        self::assertStringContainsString('              id: open-vsx', $contents);
        self::assertStringContainsString('                  OVSX_PAT: ${{ secrets.OVSX_PAT }}', $contents);
        self::assertStringContainsString("                      echo 'enabled=false' >> \"\$GITHUB_OUTPUT\"", $contents);
        self::assertStringContainsString('skipping Open VSX verification and publication', $contents);
        self::assertStringContainsString('            - name: Verify Open VSX access', $contents);
        self::assertStringContainsString("              if: \${{ steps.open-vsx.outputs.enabled == 'true' }}", $contents);
        self::assertStringContainsString('            - name: Publish release packages to Open VSX', $contents);
        self::assertStringContainsString("              if: \${{ inputs.verify_only != true && steps.open-vsx.outputs.enabled == 'true' }}", $contents);
    }

    public function testAzureOutputSuppressionDoesNotHideTheLoginVersion(): void
    {
        $contents = file_get_contents(self::ROOT.'/.github/workflows/publish-vscode.yaml');
        self::assertIsString($contents);
        self::assertDoesNotMatchRegularExpression('/^ {12}AZURE_CORE_OUTPUT:/m', $contents);
        self::assertSame(2, preg_match_all('/^ {18}AZURE_CORE_OUTPUT: none$/m', $contents));
    }

    /** @return iterable<string, array{string}> */
    public static function regularWorkflowProvider(): iterable
    {
        yield 'PHP quality' => ['quality.yaml'];
        yield 'Symfony compatibility' => ['compatibility.yaml'];
        yield 'Neovim integration' => ['neovim.yaml'];
        yield 'VS Code integration' => ['vscode.yaml'];
        yield 'Zed integration' => ['zed.yaml'];
    }

    private function dogfoodConfiguration(string $project): ProjectConfiguration
    {
        $configurations = (new ConfigurationLoader())->load([
            self::ROOT.'/tools/dogfood/projects',
        ], ['composer', 'composer-no-scripts']);
        foreach ($configurations as $configuration) {
            if ($project === $configuration->name) {
                return $configuration;
            }
        }

        self::fail(\sprintf('Dogfood project "%s" is not configured.', $project));
    }
}
