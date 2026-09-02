<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\InteractiveProcessRunner;
use Symfony\Lsp\Tools\ReleasePharBuilder;
use Symfony\Lsp\Tools\ReleasePharDownloader;
use Symfony\Lsp\Tools\ReleaseReference;

final class ReleasePharBuilderTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-release-phar-');
        $this->workspace->mkdir('resources', 'tools', 'vendor');
        $root = \dirname(__DIR__, 2);
        foreach (['smoke-test-server', 'ContentLengthProcessClient.php', 'ContentLengthMessageCodec.php', 'ContentLengthMessageException.php'] as $file) {
            copy($root.'/tools/'.$file, $this->workspace->path('tools/'.$file));
        }
        $this->workspace->write('vendor/autoload.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    #[DataProvider('referenceProvider')]
    public function testBuildsAndCommandSmokeTestsTheVersionedPhar(string $type, string $name, string $embeddedVersion): void
    {
        $boxSource = $this->workspace->write('box-source.php', <<<'PHP'
            <?php

            file_put_contents(getcwd().'/box-arguments', implode("\n", array_slice($argv, 1)));
            $version = trim((string) file_get_contents(getcwd().'/resources/version'));
            $script = <<<'PHAR'
            #!/usr/bin/env php
            <?php
            if ('--version' === ($argv[1] ?? null)) {
                fwrite(STDOUT, 'Symfony Language Tools VERSION'."\r\n");
                exit(0);
            }
            fwrite(STDERR, 'Unknown command "'.($argv[1] ?? '').'".'."\r\n");
            exit(11);
            PHAR;
            file_put_contents(getcwd().'/build/symfony-lsp.phar', str_replace('VERSION', $version, $script));
            PHP);
        $builder = new ReleasePharBuilder(
            $this->workspace->rootPath,
            new ReleasePharDownloader($boxSource, hash('sha256', (string) file_get_contents($boxSource)), 1, 0),
            new InteractiveProcessRunner(),
        );

        $phar = $builder->build(new ReleaseReference($type, $name));

        self::assertSame($this->workspace->path('build/symfony-lsp.phar'), $phar);
        self::assertFileExists($phar);
        self::assertSame($embeddedVersion."\n", file_get_contents($this->workspace->path('resources/version')));
        self::assertSame("compile\n--no-parallel", file_get_contents($this->workspace->path('box-arguments')));
        self::assertSame(file_get_contents($boxSource), file_get_contents($this->workspace->path('var/build/release/box.phar')));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function referenceProvider(): iterable
    {
        yield 'stable tag' => ['tag', 'v1.2.3', '1.2.3'];
        yield 'prerelease tag' => ['tag', 'v1.2.3-rc.1', '1.2.3-rc.1'];
        yield 'development branch' => ['branch', 'main', 'dev'];
    }

    public function testDownloadsPharBytesWithoutNewlineTranslation(): void
    {
        $contents = "PHAR\0\r\n\x1a\xff";
        $source = $this->workspace->write('binary-phar', $contents);
        $destination = $this->workspace->path('downloaded/box.phar');

        (new ReleasePharDownloader($source, hash('sha256', $contents), 1, 0))->download($destination);

        self::assertSame($contents, file_get_contents($destination));
    }

    public function testRejectsAnInvalidBuilderChecksumBeforeCompilation(): void
    {
        $boxSource = $this->workspace->write('box-source.php', "<?php\n");
        $builder = new ReleasePharBuilder(
            $this->workspace->rootPath,
            new ReleasePharDownloader($boxSource, str_repeat('0', 64), 1, 0),
            new InteractiveProcessRunner(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to download the release PHAR: checksum mismatch.');

        $builder->build(new ReleaseReference('tag', 'v1.2.3'));
    }
}
