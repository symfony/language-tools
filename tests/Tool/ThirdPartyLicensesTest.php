<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class ThirdPartyLicensesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testDistributesProductionComposerLicenses(): void
    {
        /** @var array{packages: list<array{name: string}>} $lock */
        $lock = json_decode((string) file_get_contents(self::ROOT.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);
        $packages = $lock['packages'];

        $expected = ['composer/LICENSE'];
        self::assertSame(
            $this->normalizedContents(self::ROOT.'/vendor/composer/LICENSE'),
            $this->normalizedContents(self::ROOT.'/THIRD_PARTY_LICENSES/php/composer/LICENSE'),
        );
        foreach ($packages as $package) {
            $relativePath = $package['name'].'/LICENSE';
            $expected[] = $relativePath;
            self::assertSame(
                $this->normalizedContents($this->packageLicense(self::ROOT.'/vendor/'.$package['name'])),
                $this->normalizedContents(self::ROOT.'/THIRD_PARTY_LICENSES/php/'.$relativePath),
                $package['name'].' has an outdated distributed license.',
            );
        }

        sort($expected);
        self::assertSame($expected, $this->licenseFiles('php'));
    }

    public function testDistributesProductionNpmLicenses(): void
    {
        /** @var array{packages: array<string, array{dev?: bool}>} $lock */
        $lock = json_decode((string) file_get_contents(self::ROOT.'/editor/vscode/package-lock.json'), true, flags: \JSON_THROW_ON_ERROR);
        $packages = $lock['packages'];

        $expected = [];
        foreach ($packages as $path => $package) {
            if (!str_starts_with($path, 'node_modules/') || ($package['dev'] ?? false)) {
                continue;
            }
            $expected[] = substr($path, \strlen('node_modules/')).'/LICENSE';
        }

        sort($expected);
        self::assertSame($expected, $this->licenseFiles('vscode'));
    }

    public function testDistributesZedExtensionLicenses(): void
    {
        self::assertSame(
            $this->normalizedContents(self::ROOT.'/LICENSE'),
            $this->normalizedContents(self::ROOT.'/editor/zed/LICENSE'),
        );
        self::assertGreaterThan(
            10000,
            (int) filesize(self::ROOT.'/editor/zed/THIRD_PARTY_LICENSES/zed_extension_api/LICENSE-APACHE'),
        );
        self::assertStringContainsString(
            'zed_extension_api | Apache License 2.0',
            (string) file_get_contents(self::ROOT.'/editor/zed/THIRD_PARTY_NOTICES.md'),
        );
    }

    public function testDistributesNativeDependencyLicenses(): void
    {
        $expected = [
            'runtime/lib_libiconv_0.txt',
            'runtime/lib_zlib_0.txt',
            'runtime/phpmicro-LICENSE',
            'runtime/src_php-src_0.txt',
            'tree-sitter/tree-sitter-LICENSE',
            'tree-sitter/tree-sitter-twig-LICENSE',
            'tree-sitter/tree-sitter-yaml-LICENSE',
            'tree-sitter/unicode-LICENSE',
        ];
        self::assertSame($expected, [...$this->licenseFiles('runtime', false), ...$this->licenseFiles('tree-sitter', false)]);
        foreach ($expected as $path) {
            self::assertGreaterThan(100, (int) filesize(self::ROOT.'/THIRD_PARTY_LICENSES/'.$path));
        }
    }

    public function testReleasePackagesContainThirdPartyNotices(): void
    {
        $workflow = (string) file_get_contents(self::ROOT.'/.github/workflows/release.yaml');
        $notices = (string) file_get_contents(self::ROOT.'/THIRD_PARTY_NOTICES.md');

        self::assertStringContainsString('./spc download --with-php=8.4 --for-extensions="$EXTENSIONS"', $workflow);
        self::assertStringContainsString('./spc build "$EXTENSIONS" --build-micro -P "$GITHUB_WORKSPACE/tools/spc-inject-tree-sitter.php"', $workflow);
        self::assertStringContainsString('./spc.exe build "$env:EXTENSIONS" --build-micro -P "$env:GITHUB_WORKSPACE/tools/spc-inject-tree-sitter.php"', $workflow);
        self::assertStringContainsString('EXTENSIONS: ctype,filter,iconv,mbstring,pcntl,phar,posix,tokenizer,zlib', $workflow);
        self::assertStringContainsString('EXTENSIONS: ctype,filter,iconv,mbstring,phar,tokenizer,zlib', $workflow);
        self::assertSame(2, substr_count($workflow, 'smoke-test-server'));
        self::assertStringContainsString('| PHP 8.4 series | PHP License 3.01 |', $notices);
        self::assertSame(4, substr_count($workflow, 'spc_checksum:'));
        self::assertStringContainsString("throw 'Invalid static-php-cli checksum.'", $workflow);
        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'THIRD_PARTY_NOTICES.md'));
        self::assertGreaterThanOrEqual(4, substr_count($workflow, 'THIRD_PARTY_LICENSES'));
    }

    /** @return list<string> */
    private function licenseFiles(string $directory, bool $relativeToDirectory = true): array
    {
        $root = self::ROOT.'/THIRD_PARTY_LICENSES/'.$directory;
        $files = [];
        foreach ((new Finder())->files()->in($root) as $file) {
            $files[] = ($relativeToDirectory ? '' : $directory.'/').$file->getRelativePathname();
        }
        sort($files);

        return $files;
    }

    private function normalizedContents(string $path): string
    {
        $contents = (string) file_get_contents($path);

        return rtrim(preg_replace('/[ \t]+$/m', '', $contents) ?? '')."\n";
    }

    private function packageLicense(string $directory): string
    {
        foreach (['LICENSE', 'LICENSE.txt', 'LICENSE.md'] as $name) {
            if (is_file($path = $directory.'/'.$name)) {
                return $path;
            }
        }

        self::fail('No license found for '.$directory);
    }
}
