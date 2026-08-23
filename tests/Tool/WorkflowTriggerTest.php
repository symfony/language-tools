<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    /** @return iterable<string, array{string}> */
    public static function regularWorkflowProvider(): iterable
    {
        yield 'PHP quality' => ['quality.yaml'];
        yield 'Symfony compatibility' => ['compatibility.yaml'];
        yield 'Neovim integration' => ['neovim.yaml'];
        yield 'VS Code integration' => ['vscode.yaml'];
        yield 'Zed integration' => ['zed.yaml'];
    }
}
