<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Lsp\Tools\ReleaseCommand;

final class ReleaseWorkflowTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testWorkflowYamlParses(): void
    {
        foreach ((new Finder())->files()->in(self::ROOT.'/.github/workflows')->name('*.yaml') as $workflow) {
            self::assertIsArray(Yaml::parseFile($workflow->getPathname()), $workflow->getRelativePathname());
        }
    }

    public function testReusableWorkflowBuildsAndBundlesEveryReleasePlatform(): void
    {
        $workflow = $this->workflow('build-release.yaml');

        self::assertStringContainsString("    workflow_call:\n        inputs:\n            version:", $workflow);
        self::assertStringContainsString('Release reference, either dev or vX.Y.Z[-PRERELEASE]', $workflow);
        self::assertStringContainsString('if [[ "$VERSION" = dev ]]', $workflow);
        self::assertStringContainsString('^v[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$', $workflow);
        self::assertSame(2, substr_count($workflow, 'tools/build-release-phar'));
        self::assertSame(2, substr_count($workflow, 'tools/package-release'));
        self::assertSame(4, substr_count($workflow, 'spc_checksum:'));
        self::assertSame(2, substr_count($workflow, '- name: Verify static-php-cli'));
        self::assertStringContainsString('name: windows-x64', $workflow);
        self::assertStringContainsString('timeout-minutes: 45', $workflow);
        self::assertStringContainsString('test "$(find dist -maxdepth 1 -type f | wc -l)" -eq "${#assets[@]}"', $workflow);
        self::assertStringContainsString("'{commit: \$commit, version: \$version}'", $workflow);
        self::assertStringContainsString('name: symfony-lsp-release-candidate-${{ inputs.version }}-${{ github.sha }}', $workflow);

        foreach ($this->releaseAssetSuffixes() as $asset) {
            self::assertStringContainsString('"symfony-lsp-$VERSION-'.$asset.'"', $workflow);
        }
        self::assertStringContainsString('release-candidate/SHA256SUMS', $workflow);
        self::assertStringContainsString('release-candidate/RELEASE_NOTES.md', $workflow);
        self::assertStringContainsString('release-candidate/RELEASE_MANIFEST.json', $workflow);
    }

    public function testCandidateWorkflowBuildsDevDailyAndExactVersionsOnDemand(): void
    {
        $workflow = $this->workflow('release-candidate.yaml');

        self::assertStringContainsString("    schedule:\n        - cron:", $workflow);
        self::assertStringContainsString("    workflow_dispatch:\n        inputs:\n            version:", $workflow);
        self::assertStringContainsString("run-name: Release candidate \${{ github.event_name == 'workflow_dispatch' && inputs.version || 'dev' }}", $workflow);
        self::assertStringContainsString('uses: ./.github/workflows/build-release.yaml', $workflow);
        self::assertStringContainsString("version: \${{ github.event_name == 'workflow_dispatch' && inputs.version || 'dev' }}", $workflow);
        self::assertStringContainsString('uses: ./.github/workflows/publish-vscode.yaml', $workflow);
        self::assertStringContainsString('needs: build', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString("'Development release candidate'", $this->workflow('build-release.yaml'));
        self::assertStringNotContainsString('secrets: inherit', $workflow);
        self::assertStringContainsString('verify_only: true', $workflow);
    }

    public function testProductionPackagingRunsFastProductionPharChecksOnEveryChange(): void
    {
        $workflow = $this->workflow('packaging.yaml');

        self::assertStringContainsString("    pull_request:\n    push:\n        branches: [main]\n    workflow_dispatch:", $workflow);
        self::assertStringNotContainsString('paths:', $workflow);
        self::assertStringNotContainsString('paths-ignore:', $workflow);
        self::assertStringContainsString('os: [ubuntu-latest, windows-2022]', $workflow);
        self::assertStringContainsString('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader', $workflow);
        self::assertStringContainsString('php tools/build-release-phar branch dev', $workflow);
        self::assertStringNotContainsString('Smoke-test PHAR commands on Windows', $workflow);
        self::assertStringContainsString('php tools/smoke-test-server --php --php-option="extension=$GITHUB_WORKSPACE/var/build/tree_sitter/modules/symfony_lsp_tree_sitter.so" build/symfony-lsp.phar dev', $workflow);
    }

    public function testTagReleasePromotesOnlyTheExactSuccessfulCandidate(): void
    {
        $workflow = $this->workflow('release.yaml');

        self::assertStringContainsString('--workflow=release-candidate.yaml', $workflow);
        self::assertStringContainsString('--commit="$GITHUB_SHA"', $workflow);
        self::assertStringContainsString('--event=workflow_dispatch', $workflow);
        self::assertStringContainsString('--status=success', $workflow);
        self::assertStringContainsString('GH_REPO: ${{ github.repository }}', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString('first(.[] | select(.headSha == \\"$GITHUB_SHA\\" and .displayTitle == \\"Release candidate $GITHUB_REF_NAME\\") | .databaseId) // empty', $workflow);
        self::assertStringNotContainsString('| head -1', $workflow);
        self::assertStringContainsString('name: ${{ env.CANDIDATE_ARTIFACT }}', $workflow);
        self::assertStringContainsString('run-id: ${{ steps.candidate.outputs.run_id }}', $workflow);
        self::assertStringContainsString("'.commit == \$commit and .version == \$version", $workflow);
        self::assertStringContainsString('sha256sum --check --strict SHA256SUMS', $workflow);
        self::assertStringContainsString('body_path: candidate/RELEASE_NOTES.md', $workflow);
        self::assertSame(1, substr_count($workflow, 'candidate/RELEASE_MANIFEST.json'));
        self::assertStringNotContainsString('secrets: inherit', $workflow);
        self::assertStringNotContainsString('build-release-phar', $workflow);
        self::assertStringNotContainsString('package-release', $workflow);
        self::assertStringNotContainsString('static-php-cli', $workflow);

        foreach ($this->releaseAssetSuffixes() as $asset) {
            self::assertStringContainsString('"symfony-lsp-$GITHUB_REF_NAME-'.$asset.'"', $workflow);
        }
    }

    public function testQualityValidatesComposerAutoloading(): void
    {
        self::assertStringContainsString('run: composer autoload-check', $this->workflow('quality.yaml'));
    }

    public function testTransientRetryStepsMatchTheWorkflows(): void
    {
        $available = ['Set up job'];
        foreach ((new Finder())->files()->in(self::ROOT.'/.github/workflows')->name('*.yaml') as $workflow) {
            preg_match_all('/^\s+- name: (.+)$/m', $workflow->getContents(), $names);
            array_push($available, ...$names[1]);
            preg_match_all('/^\s+- uses: ([^\s]+)$/m', $workflow->getContents(), $actions);
            foreach ($actions[1] as $action) {
                $available[] = 'Run '.$action;
            }
        }

        $constant = (new \ReflectionClass(ReleaseCommand::class))->getReflectionConstant('TRANSIENT_WORKFLOW_STEPS');
        self::assertNotFalse($constant);
        /** @var list<string> $steps */
        $steps = $constant->getValue();
        foreach ($steps as $step) {
            self::assertContains($step, $available);
        }
    }

    public function testWorkflowsUseTheApprovedActionMajors(): void
    {
        $approved = [
            'actions/cache' => 'v6',
            'actions/checkout' => 'v7',
            'actions/download-artifact' => 'v8',
            'actions/setup-node' => 'v7',
            'actions/upload-artifact' => 'v7',
            'azure/login' => 'v3',
            'ramsey/composer-install' => 'v4',
            'shivammathur/setup-php' => 'v2',
            'softprops/action-gh-release' => 'v3',
        ];
        $seen = [];
        foreach ((new Finder())->files()->in(self::ROOT.'/.github/workflows')->name('*.yaml') as $workflow) {
            preg_match_all('/uses:\s+([^@\s]+)@(v[0-9]+)/', $workflow->getContents(), $matches, \PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (!isset($approved[$match[1]])) {
                    continue;
                }
                $seen[$match[1]] = true;
                self::assertSame($approved[$match[1]], $match[2], $workflow->getRelativePathname().' uses an outdated action major.');
            }
        }

        self::assertSame(array_keys($approved), array_keys(array_intersect_key($approved, $seen)));
    }

    /** @return list<string> */
    private function releaseAssetSuffixes(): array
    {
        return [
            'linux-x64.tar.gz',
            'linux-arm64.tar.gz',
            'macos-x64.tar.gz',
            'macos-arm64.tar.gz',
            'windows-x64.zip',
            'linux-x64.vsix',
            'linux-arm64.vsix',
            'darwin-x64.vsix',
            'darwin-arm64.vsix',
            'win32-x64.vsix',
        ];
    }

    private function workflow(string $name): string
    {
        $contents = file_get_contents(self::ROOT.'/.github/workflows/'.$name);
        self::assertIsString($contents);

        return $contents;
    }
}
