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

        $documentationRows = array_map(static function (array $match): array {
            preg_match_all('/^      - (Yes|No)$/m', $match[2], $support);

            return [
                'name' => $match[1],
                'support' => array_map(static fn (string $cell): bool => 'Yes' === $cell, $support[1]),
            ];
        }, $matches);
        self::assertNotSame([], $documentationRows);

        $marketplace = (string) file_get_contents(self::ROOT.'/editor/vscode/MARKETPLACE.md');
        $table = explode('| Integration | Completion | Hover | Definition | References | Rename | Diagnostics |', $marketplace, 2)[1] ?? null;
        self::assertNotNull($table);
        $table = explode("\n\n", $table, 2)[0];
        preg_match_all('/^\| ([^|:-][^|]*) \|(.+)\|$/m', $table, $matches, \PREG_SET_ORDER);

        $marketplaceRows = array_map(static fn (array $match): array => [
            'name' => trim($match[1]),
            'support' => array_map(static fn (string $cell): bool => '✓' === trim($cell), explode('|', $match[2])),
        ], $matches);

        self::assertSame($documentationRows, $marketplaceRows);
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
