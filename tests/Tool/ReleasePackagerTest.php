<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\InteractiveProcessRunner;
use Symfony\Lsp\Tools\ReleasePackager;
use Symfony\Lsp\Tools\ReleaseReference;

final class ReleasePackagerTest extends TestCase
{
    private TestWorkspace $workspace;
    private string|false $previousSmokeCalls;
    private string|false $previousSmokeExitCode;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-release-package-');
        $this->workspace->mkdir(
            'build',
            'tools',
            'THIRD_PARTY_LICENSES/php/vendor/package',
            'THIRD_PARTY_LICENSES/runtime',
            'THIRD_PARTY_LICENSES/tree-sitter',
        );
        $this->workspace->executable('build/symfony-lsp', "#!/bin/sh\nexit 0\n");
        $this->workspace->executable('build/symfony-lsp.exe', 'windows executable');
        $this->workspace->write('LICENSE', 'project license');
        $this->workspace->write('THIRD_PARTY_NOTICES.md', 'third-party notices');
        $this->workspace->write('THIRD_PARTY_LICENSES/php/vendor/package/LICENSE', 'PHP package license');
        $this->workspace->write('THIRD_PARTY_LICENSES/runtime/src_php-src_0.txt', 'PHP runtime license');
        $this->workspace->write('THIRD_PARTY_LICENSES/tree-sitter/tree-sitter-twig-LICENSE', 'Tree-sitter Twig license');
        $this->workspace->executable('tools/smoke-test-server', <<<'PHP'
            #!/usr/bin/env php
            <?php
            file_put_contents((string) getenv('SMOKE_CALLS'), json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            exit((int) (getenv('SMOKE_EXIT_CODE') ?: 0));
            PHP);
        $this->previousSmokeCalls = getenv('SMOKE_CALLS');
        $this->previousSmokeExitCode = getenv('SMOKE_EXIT_CODE');
        putenv('SMOKE_CALLS='.$this->workspace->path('smoke-calls'));
        putenv('SMOKE_EXIT_CODE=0');
    }

    protected function tearDown(): void
    {
        false === $this->previousSmokeCalls ? putenv('SMOKE_CALLS') : putenv('SMOKE_CALLS='.$this->previousSmokeCalls);
        false === $this->previousSmokeExitCode ? putenv('SMOKE_EXIT_CODE') : putenv('SMOKE_EXIT_CODE='.$this->previousSmokeExitCode);
        $this->workspace->cleanup();
    }

    #[DataProvider('packageProvider')]
    public function testCreatesPlatformArchiveWithLockedLayoutAndSmokeMode(
        string $platform,
        string $type,
        string $refName,
        string $embeddedVersion,
        string $executable,
        string $extension,
        bool $socketMode,
    ): void {
        $archive = (new ReleasePackager($this->workspace->rootPath, new InteractiveProcessRunner()))
            ->package($platform, new ReleaseReference($type, $refName));
        $packageName = 'symfony-lsp-'.$refName.'-'.$platform;

        self::assertSame($this->workspace->path('dist/'.$packageName.'.'.$extension), $archive);
        self::assertFileExists($archive);
        self::assertSame([
            $packageName.'/LICENSE',
            $packageName.'/THIRD_PARTY_LICENSES/php/vendor/package/LICENSE',
            $packageName.'/THIRD_PARTY_LICENSES/runtime/src_php-src_0.txt',
            $packageName.'/THIRD_PARTY_LICENSES/tree-sitter/tree-sitter-twig-LICENSE',
            $packageName.'/THIRD_PARTY_NOTICES.md',
            $packageName.'/'.$executable,
        ], $this->archiveFiles($archive));
        self::assertSame(
            [...($socketMode ? ['--socket'] : []), $this->workspace->path('dist/'.$packageName.'/'.$executable), $embeddedVersion],
            json_decode(trim((string) file_get_contents($this->workspace->path('smoke-calls'))), true, flags: \JSON_THROW_ON_ERROR),
        );
        if (!$socketMode) {
            self::assertSame(0755, fileperms($this->workspace->path('dist/'.$packageName.'/'.$executable)) & 0777);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, string, bool}> */
    public static function packageProvider(): iterable
    {
        yield 'Zed Linux x64 stable asset' => ['linux-x64', 'tag', 'v1.2.3', '1.2.3', 'symfony-lsp', 'tar.gz', false];
        yield 'Linux arm64 development asset' => ['linux-arm64', 'branch', 'main', 'dev', 'symfony-lsp', 'tar.gz', false];
        yield 'macOS x64 stable asset' => ['macos-x64', 'tag', 'v1.2.3', '1.2.3', 'symfony-lsp', 'tar.gz', false];
        yield 'Zed macOS arm64 prerelease asset' => ['macos-arm64', 'tag', 'v1.2.3-rc.1', '1.2.3-rc.1', 'symfony-lsp', 'tar.gz', false];
        yield 'Windows socket asset' => ['windows-x64', 'tag', 'v1.2.3', '1.2.3', 'symfony-lsp.exe', 'zip', true];
    }

    public function testDoesNotArchiveAPackageThatFailsItsSmokeTest(): void
    {
        putenv('SMOKE_EXIT_CODE=1');
        $packager = new ReleasePackager($this->workspace->rootPath, new InteractiveProcessRunner());

        try {
            $packager->package('windows-x64', new ReleaseReference('tag', 'v1.2.3'));
            self::fail('The failing package smoke test should have stopped packaging.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The packaged server smoke test failed.', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->workspace->path('dist/symfony-lsp-v1.2.3-windows-x64.zip'));
    }

    public function testRejectsPackagesWithoutRequiredRuntimeLicenses(): void
    {
        unlink($this->workspace->path('THIRD_PARTY_LICENSES/runtime/src_php-src_0.txt'));
        $packager = new ReleasePackager($this->workspace->rootPath, new InteractiveProcessRunner());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required release license "runtime/src_php-src_0.txt" is missing.');

        $packager->package('linux-x64', new ReleaseReference('tag', 'v1.2.3'));
    }

    /** @return list<string> */
    private function archiveFiles(string $archivePath): array
    {
        $extractPath = $this->workspace->path('extracted-'.bin2hex(random_bytes(4)));
        (new \PharData($archivePath))->extractTo($extractPath);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($extractPath) + 1));
            }
        }
        sort($files);

        return $files;
    }
}
