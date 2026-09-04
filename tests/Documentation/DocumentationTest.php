<?php

namespace Symfony\Lsp\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class DocumentationTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testFeatureTableLinksToEveryIntegrationPage(): void
    {
        $index = (string) file_get_contents(self::ROOT.'/docs/features/index.rst');
        preg_match_all('/^\.\. _`[^`]+`: ([^\s\/]+\.rst)$/m', $index, $matches);

        $pages = [];
        foreach ((new Finder())->files()->in(self::ROOT.'/docs/features')->name('*.rst')->notName('index.rst') as $file) {
            $pages[] = $file->getFilename();
        }

        sort($pages);
        sort($matches[1]);

        self::assertSame($pages, $matches[1]);
        $integrationPages = array_values(array_diff($pages, ['headless-diagnostics.rst']));
        self::assertSame(\count($integrationPages), preg_match_all('/^    \* - `[^`]+`_$/m', $index));
    }

    public function testMarketplaceFeatureTableMatchesDocumentation(): void
    {
        $index = (string) file_get_contents(self::ROOT.'/docs/features/index.rst');
        preg_match_all('/^    \* - `(.+)`_\R((?:      - (?:Yes|No)\R){6})/m', $index, $matches, \PREG_SET_ORDER);

        $documentationRows = array_column(array_map(static function (array $match): array {
            preg_match_all('/^      - (Yes|No)$/m', $match[2], $support);

            return [
                'name' => $match[1],
                'support' => array_map(static fn (string $cell): bool => 'Yes' === $cell, $support[1]),
            ];
        }, $matches), null, 'name');
        self::assertNotSame([], $documentationRows);

        $marketplace = (string) file_get_contents(self::ROOT.'/editor/vscode/MARKETPLACE.md');
        preg_match_all('/^\| ([^|:-][^|]*) \| ([✓·]) \| ([✓·]) \| ([✓·]) \| ([✓·]) \| ([✓·]) \| ([✓·]) \|$/mu', $marketplace, $matches, \PREG_SET_ORDER);
        $marketplaceRows = [];
        foreach ($matches as $match) {
            $marketplaceRows[$match[1]] = [
                'name' => $match[1],
                'support' => array_map(static fn (string $cell): bool => '✓' === $cell, \array_slice($match, 2)),
            ];
        }
        ksort($documentationRows);
        ksort($marketplaceRows);

        self::assertSame($documentationRows, $marketplaceRows);
    }

    public function testZedInstallationDocumentsDevelopmentExtension(): void
    {
        foreach (['README.md', 'docs/index.rst', 'docs/editors/zed.rst'] as $path) {
            self::assertStringContainsString(
                'development extension',
                (string) file_get_contents(self::ROOT.'/'.$path),
                $path,
            );
        }

        $guide = (string) file_get_contents(self::ROOT.'/docs/editors/zed.rst');
        self::assertStringContainsString('rustup target add wasm32-wasip2', $guide);
        self::assertStringContainsString('zed: install dev extension', $guide);
        self::assertStringContainsString('editor/zed/', $guide);
    }

    public function testDocumentationUsesGitHubCompatibleRst(): void
    {
        foreach ((new Finder())->files()->in(self::ROOT.'/docs')->name('*.rst') as $file) {
            $contents = $file->getContents();
            $path = $file->getRelativePathname();

            self::assertStringNotContainsString('.. toctree::', $contents, $path);
            self::assertStringNotContainsString(':doc:', $contents, $path);
            self::assertStringNotContainsString(':ref:', $contents, $path);
        }
    }

    public function testInlineCodeUsesLiteralBackslashes(): void
    {
        foreach ((new Finder())->files()->in(self::ROOT.'/docs')->name('*.rst') as $file) {
            $contents = $file->getContents();
            preg_match_all('/``([^`\n]+)``/', $contents, $matches);

            foreach ($matches[1] as $inlineCode) {
                self::assertStringNotContainsString('\\\\', $inlineCode, $file->getRelativePathname());
            }
        }
    }
}
