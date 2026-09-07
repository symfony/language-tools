<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ConfigurationException;
use Symfony\Lsp\Tools\Dogfood\ScenarioLocator;

final class ScenarioLocatorTest extends TestCase
{
    private const CONTROLLER = <<<'PHP'
        <?php

        namespace App\Controller;

        final class BlogController
        {
            public function index(): Response
            {
                return $this->redirectToRoute('blog_index');
            }
        }

        PHP;

    private const TEMPLATE = <<<'TWIG'
        {% extends 'base.html.twig' %}

        {{ 'héros 🎉' ~ path('blog_index') }}

        TWIG;

    private string $root;

    protected function setUp(): void
    {
        $this->root = Path::join(sys_get_temp_dir(), 'symfony-lsp-locator-'.bin2hex(random_bytes(8)));
        $this->write('src/Controller/BlogController.php', self::CONTROLLER);
        $this->write('src/Duplicate.php', "<?php\n// TODO\n// TODO\n");
        $this->write('templates/blog/index.html.twig', self::TEMPLATE);
        $this->write('config/services.yaml', "services:\n    _defaults:\n        autowire: true\n");
        $this->write('vendor/acme/package/src/Thing.php', "<?php\n// TODO\n");
        $this->write('var/cache/dev/container.php', "<?php\n// TODO\n");
        $this->write('node_modules/pkg/index.js', "// TODO\n");
        $this->write('.git/hooks/pre-commit.php', "<?php\n// TODO\n");
        $this->write('composer.lock', "{}\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testLocatesAnAnchorWithTheDefaultOffset(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'id' => 'route.hover',
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
            'expect' => ['hover' => ['includes' => ['blog_index']]],
        ]);

        self::assertSame('src/Controller/BlogController.php', $document->file);
        self::assertSame('file://'.$this->realPath('src/Controller/BlogController.php'), $document->uri);
        self::assertSame(self::CONTROLLER, $document->text);
        self::assertSame('php', $document->languageId);
        self::assertSame(8, $document->position->line);
        self::assertSame(22, $document->position->character);
        self::assertSame(strpos(self::CONTROLLER, 'redirectToRoute'), $document->anchorOffset);
        self::assertSame($document->anchorOffset, $document->byteOffset);
    }

    public function testLocatesAnOffsetInsideTheAnchor(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
            'offset' => 17,
        ]);

        self::assertSame(8, $document->position->line);
        self::assertSame(39, $document->position->character);
        self::assertSame($document->anchorOffset + 17, $document->byteOffset);
        self::assertSame('blog_index', substr($document->text, $document->byteOffset, 10));
    }

    public function testCountsPositionsInUtf16CodeUnits(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'templates/blog/index.html.twig',
            'anchor' => "'blog_index'",
        ]);

        self::assertSame('twig', $document->languageId);
        self::assertSame(2, $document->position->line);
        self::assertSame(21, $document->position->character);
    }

    public function testLocatesAnOffsetInsideAMultibyteAnchor(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'templates/blog/index.html.twig',
            'anchor' => "'héros 🎉'",
            'offset' => 8,
        ]);

        self::assertSame(2, $document->position->line);
        self::assertSame(10, $document->position->character);
    }

    public function testAcceptsAnOffsetAtTheEndOfTheAnchor(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'config/services.yaml',
            'anchor' => 'autowire',
            'offset' => 8,
        ]);

        self::assertSame('yaml', $document->languageId);
        self::assertSame(2, $document->position->line);
        self::assertSame(16, $document->position->character);
    }

    public function testLocatesAnAnchorEndingAtTheEndOfTheFile(): void
    {
        $this->write('config/eof.yaml', 'framework: true');

        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'config/eof.yaml',
            'anchor' => 'true',
            'offset' => 4,
        ]);

        self::assertSame(0, $document->position->line);
        self::assertSame(15, $document->position->character);
        self::assertSame(15, $document->byteOffset);
    }

    public function testLocatesAnAnchorAfterWindowsLineEndings(): void
    {
        $this->write('config/windows.yaml', "framework:\r\n    secret: '%env(APP_SECRET)%'\r\n");

        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'config/windows.yaml',
            'anchor' => 'APP_SECRET',
        ]);

        self::assertSame(1, $document->position->line);
        self::assertSame(18, $document->position->character);
    }

    public function testPrefersAnOverlayOverTheStoredFile(): void
    {
        $locator = new ScenarioLocator();
        $uri = 'file://'.$this->realPath('src/Controller/BlogController.php');
        $overlay = str_replace('blog_index', 'blog_missing', self::CONTROLLER);

        $document = $locator->locate($this->root, [
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_missing'",
        ], [$uri => $overlay]);

        self::assertSame($overlay, $document->text);
        self::assertSame(8, $document->position->line);
    }

    public function testIgnoresOverlaysOfOtherDocuments(): void
    {
        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
        ], ['file:///elsewhere/Other.php' => 'overlay']);

        self::assertSame(self::CONTROLLER, $document->text);
    }

    public function testResolvesSymlinkedFilesToTheirCanonicalPath(): void
    {
        symlink($this->realPath('src/Controller/BlogController.php'), Path::join($this->root, 'src/Alias.php'));

        $document = (new ScenarioLocator())->locate($this->root, [
            'file' => 'src/Alias.php',
            'anchor' => "redirectToRoute('blog_index'",
        ]);

        self::assertSame('src/Controller/BlogController.php', $document->file);
        self::assertSame('file://'.$this->realPath('src/Controller/BlogController.php'), $document->uri);
    }

    public function testRejectsSymlinksEscapingTheProject(): void
    {
        $outside = Path::join(sys_get_temp_dir(), 'symfony-lsp-outside-'.bin2hex(random_bytes(8)).'.php');
        file_put_contents($outside, "<?php\n// TODO\n");
        symlink($outside, Path::join($this->root, 'src/Escape.php'));

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage('resolves outside of');

            (new ScenarioLocator())->locate($this->root, ['file' => 'src/Escape.php', 'anchor' => 'TODO']);
        } finally {
            unlink($outside);
        }
    }

    public function testRejectsSymlinksIntoExcludedDirectories(): void
    {
        symlink($this->realPath('vendor/acme/package/src/Thing.php'), Path::join($this->root, 'src/Vendored.php'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('excluded directory "vendor"');

        (new ScenarioLocator())->locate($this->root, ['file' => 'src/Vendored.php', 'anchor' => 'TODO']);
    }

    public function testRejectsAMissingProjectRoot(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        (new ScenarioLocator())->locate(Path::join($this->root, 'missing'), [
            'file' => 'src/Controller/BlogController.php',
            'anchor' => 'TODO',
        ]);
    }

    public function testRejectsFilesThatAreNotValidUtf8(): void
    {
        $this->write('src/Binary.php', "<?php\n// \xFF\xFE TODO\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not valid UTF-8');

        (new ScenarioLocator())->locate($this->root, ['file' => 'src/Binary.php', 'anchor' => 'TODO']);
    }

    #[DataProvider('languageProvider')]
    public function testResolvesLanguageIds(string $file, string $languageId): void
    {
        $this->write($file, "anchor\n");

        $document = (new ScenarioLocator())->locate($this->root, ['file' => $file, 'anchor' => 'anchor']);

        self::assertSame($languageId, $document->languageId);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languageProvider(): iterable
    {
        yield 'php' => ['src/Service.php', 'php'];
        yield 'twig' => ['templates/page.html.twig', 'twig'];
        yield 'yaml' => ['config/packages/twig.yaml', 'yaml'];
        yield 'yml' => ['config/packages/framework.yml', 'yaml'];
        yield 'xml' => ['config/services.xml', 'xml'];
        yield 'xlf' => ['translations/messages.en.xlf', 'xml'];
        yield 'xliff' => ['translations/messages.fr.xliff', 'xml'];
        yield 'javascript' => ['assets/app.js', 'javascript'];
        yield 'module' => ['assets/controllers/hello.mjs', 'javascript'];
        yield 'typescript' => ['assets/app.ts', 'typescript'];
        yield 'json' => ['importmap.json', 'json'];
        yield 'ini' => ['config/settings.ini', 'ini'];
        yield 'dotenv' => ['.env', 'dotenv'];
        yield 'dotenv variant' => ['.env.local', 'dotenv'];
        yield 'uppercase extension' => ['config/services.XML', 'xml'];
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('invalidScenarioProvider')]
    public function testRejectsUnsafeOrDriftedScenarios(array $scenario, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ScenarioLocator())->locate($this->root, $scenario);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidScenarioProvider(): iterable
    {
        $anchor = "redirectToRoute('blog_index'";

        yield 'missing file' => [['anchor' => $anchor], 'relative path inside the project'];
        yield 'absolute file' => [['file' => '/etc/passwd', 'anchor' => $anchor], 'relative path inside the project'];
        yield 'home file' => [['file' => '~/.ssh/id_rsa', 'anchor' => $anchor], 'relative path inside the project'];
        yield 'traversal file' => [['file' => 'src/../../etc/passwd', 'anchor' => $anchor], 'relative path inside the project'];
        yield 'backslash file' => [['file' => 'src\\Controller\\BlogController.php', 'anchor' => $anchor], 'relative path inside the project'];
        yield 'uri file' => [['file' => 'file:///etc/passwd', 'anchor' => $anchor], 'relative path inside the project'];
        yield 'null byte file' => [['file' => "src/Controller/BlogController.php\0", 'anchor' => $anchor], 'relative path inside the project'];
        yield 'unknown file' => [['file' => 'src/Controller/MissingController.php', 'anchor' => $anchor], 'does not exist in'];
        yield 'directory' => [['file' => 'src/Controller', 'anchor' => $anchor], 'is not a file'];
        yield 'vendor file' => [['file' => 'vendor/acme/package/src/Thing.php', 'anchor' => 'TODO'], 'excluded directory "vendor"'];
        yield 'var file' => [['file' => 'var/cache/dev/container.php', 'anchor' => 'TODO'], 'excluded directory "var"'];
        yield 'node_modules file' => [['file' => 'node_modules/pkg/index.js', 'anchor' => 'TODO'], 'excluded directory "node_modules"'];
        yield 'git file' => [['file' => '.git/hooks/pre-commit.php', 'anchor' => 'TODO'], 'excluded directory ".git"'];
        yield 'unsupported language' => [['file' => 'composer.lock', 'anchor' => 'TODO'], 'no supported language'];
        yield 'missing anchor key' => [['file' => 'src/Controller/BlogController.php'], 'non-empty UTF-8 source excerpt'];
        yield 'empty anchor' => [['file' => 'src/Controller/BlogController.php', 'anchor' => ''], 'non-empty UTF-8 source excerpt'];
        yield 'binary anchor' => [['file' => 'src/Controller/BlogController.php', 'anchor' => "TODO\0"], 'non-empty UTF-8 source excerpt'];
        yield 'drifted anchor' => [['file' => 'src/Controller/BlogController.php', 'anchor' => "redirectToRoute('blog_archive'"], 'no longer appears in'];
        yield 'ambiguous anchor' => [['file' => 'src/Duplicate.php', 'anchor' => 'TODO'], 'appears 2 times in'];
        yield 'negative offset' => [['file' => 'src/Controller/BlogController.php', 'anchor' => $anchor, 'offset' => -1], 'byte offset within its 28 byte anchor'];
        yield 'overflowing offset' => [['file' => 'src/Controller/BlogController.php', 'anchor' => $anchor, 'offset' => 29], 'byte offset within its 28 byte anchor'];
        yield 'float offset' => [['file' => 'src/Controller/BlogController.php', 'anchor' => $anchor, 'offset' => 1.5], 'byte offset within its 28 byte anchor'];
        yield 'split character offset' => [['file' => 'templates/blog/index.html.twig', 'anchor' => "'héros 🎉'", 'offset' => 3], 'falls inside a character'];
    }

    private function write(string $file, string $contents): void
    {
        $path = Path::join($this->root, $file);
        (new Filesystem())->mkdir(\dirname($path));
        file_put_contents($path, $contents);
    }

    private function realPath(string $file): string
    {
        return (string) realpath(Path::join($this->root, $file));
    }
}
