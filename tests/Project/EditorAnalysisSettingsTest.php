<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\AnalysisSettings;

final class EditorAnalysisSettingsTest extends TestCase
{
    public function testVsCodeContributesEveryServerAnalysisSetting(): void
    {
        /** @var array{contributes: array{configuration: array{properties: array<string, mixed>}}} $package */
        $package = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/editor/vscode/package.json'), true, flags: \JSON_THROW_ON_ERROR);
        $contributed = array_map(
            static fn (string $name): string => substr($name, \strlen('symfonyLsp.')),
            array_keys($package['contributes']['configuration']['properties']),
        );
        $contributed = array_values(array_diff($contributed, ['memoryLimit', 'serverPath', 'trace']));
        $expected = [...AnalysisSettings::PROJECT_KEYS, 'projectRoots'];
        sort($contributed);
        sort($expected);

        self::assertSame($expected, $contributed);
    }
}
