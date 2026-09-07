<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ConfigurationException;
use Symfony\Lsp\Tools\Dogfood\ScenarioManifestLoader;

final class ScenarioManifestLoaderTest extends TestCase
{
    private const REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-scenarios-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLoadsMinimalManifest(): void
    {
        $manifest = (new ScenarioManifestLoader())->load($this->write(self::manifest()));

        self::assertSame(self::REVISION, $manifest->revision);
        self::assertSame([], $manifest->diagnostics);
        self::assertSame([[
            'id' => 'route.hover',
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
            'offset' => 0,
            'expect' => ['hover' => ['includes' => ['blog_index']]],
        ]], $manifest->scenarios);
    }

    public function testLoadsScenarioMutations(): void
    {
        $manifest = (new ScenarioManifestLoader())->load($this->write(self::manifest([
            'scenarios' => [self::scenario([
                'offset' => 17,
                'newName' => 'blog_archive',
                'expect' => ['rename' => ['equals' => ['src/Controller/BlogController.php:12']]],
                'edit' => [
                    'before' => "'blog_index'",
                    'after' => "'blog_missing'",
                    'anchor' => "'blog_missing'",
                    'offset' => 1,
                    'expect' => ['diagnostics' => ['includes' => ['unknown route']]],
                    'applyCodeAction' => 'Create route "blog_missing"',
                    'afterFix' => ['diagnostics' => ['equals' => []]],
                ],
            ])],
        ])));

        self::assertSame([[
            'id' => 'route.hover',
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
            'offset' => 17,
            'expect' => ['rename' => ['equals' => ['src/Controller/BlogController.php:12']]],
            'newName' => 'blog_archive',
            'edit' => [
                'before' => "'blog_index'",
                'after' => "'blog_missing'",
                'file' => 'src/Controller/BlogController.php',
                'expect' => ['diagnostics' => ['includes' => ['unknown route']]],
                'anchor' => "'blog_missing'",
                'offset' => 1,
                'applyCodeAction' => 'Create route "blog_missing"',
                'afterFix' => ['diagnostics' => ['equals' => []]],
            ],
        ]], $manifest->scenarios);
    }

    public function testAcceptsAnExplicitlyEmptyExpectation(): void
    {
        $manifest = (new ScenarioManifestLoader())->load($this->write(self::manifest([
            'scenarios' => [self::scenario(['expect' => ['definition' => ['equals' => []]]])],
        ])));

        self::assertSame(['definition' => ['equals' => []]], $manifest->scenarios[0]['expect']);
    }

    public function testBindsTheManifestToTheReviewedRevision(): void
    {
        $path = $this->write(self::manifest());

        self::assertSame(self::REVISION, (new ScenarioManifestLoader())->load($path, self::REVISION)->revision);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is reviewed for revision "'.self::REVISION.'" but the project is pinned to "'.str_repeat('b', 40).'"');

        (new ScenarioManifestLoader())->load($path, str_repeat('b', 40));
    }

    public function testSortsDiagnosticsCanonicallyAndKeepsDuplicates(): void
    {
        $first = self::diagnostic(['path' => 'src/Controller/BlogController.php', 'range' => self::range(12, 4, 12, 9)]);
        $second = self::diagnostic(['path' => 'src/Controller/BlogController.php', 'range' => self::range(12, 10, 12, 20)]);
        $third = self::diagnostic(['path' => 'templates/blog/index.html.twig', 'range' => self::range(1, 0, 1, 5)]);

        $manifest = (new ScenarioManifestLoader())->load($this->write(self::manifest([
            'diagnostics' => [$third, $second, $first, $first],
        ])));

        self::assertSame([$first, $first, $second, $third], $manifest->diagnostics);
    }

    public function testAcceptsEveryCheckSeverityName(): void
    {
        $diagnostics = array_map(
            static fn (string $severity): array => self::diagnostic(['severity' => $severity]),
            ['warning', 'error', 'hint', 'information'],
        );

        $manifest = (new ScenarioManifestLoader())->load($this->write(self::manifest(['diagnostics' => $diagnostics])));

        self::assertSame(['error', 'hint', 'information', 'warning'], array_column($manifest->diagnostics, 'severity'));
    }

    public function testRejectsAMissingManifest(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        (new ScenarioManifestLoader())->load(Path::join($this->directory, 'missing.json'));
    }

    public function testRejectsInvalidJson(): void
    {
        $path = Path::join($this->directory, 'symfony-demo.json');
        file_put_contents($path, '{');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid JSON');

        (new ScenarioManifestLoader())->load($path);
    }

    public function testRejectsANonObjectManifest(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be an object');

        (new ScenarioManifestLoader())->load($this->write(['scenarios']));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidManifestProvider')]
    public function testRejectsInvalidManifests(array $overrides, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ScenarioManifestLoader())->load($this->write(self::manifest($overrides)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidManifestProvider(): iterable
    {
        yield 'unknown key' => [['project' => 'symfony-demo'], 'Unknown key "project"'];
        yield 'missing version' => [['version' => null], '"version": 1'];
        yield 'unsupported version' => [['version' => 2], '"version": 1'];
        yield 'missing revision' => [['revision' => null], 'full lowercase commit hash'];
        yield 'short revision' => [['revision' => 'abc1234'], 'full lowercase commit hash'];
        yield 'uppercase revision' => [['revision' => strtoupper(self::REVISION)], 'full lowercase commit hash'];
        yield 'missing scenarios' => [['scenarios' => null], 'non-empty list of scenarios'];
        yield 'zero scenarios' => [['scenarios' => []], 'non-empty list of scenarios'];
        yield 'scenario map' => [['scenarios' => ['route' => []]], 'non-empty list of scenarios'];
        yield 'scenario list entry' => [['scenarios' => [['a', 'b']]], 'must be an object'];
        yield 'duplicate scenarios' => [['scenarios' => [self::scenario(), self::scenario()]], 'Duplicate scenario "route.hover"'];
        yield 'missing diagnostics' => [['diagnostics' => null], 'must declare a "diagnostics" baseline'];
        yield 'diagnostics map' => [['diagnostics' => ['src' => []]], 'list of baseline entries'];
        yield 'diagnostic list entry' => [['diagnostics' => [['a']]], 'must be an object'];
        yield 'unknown diagnostic key' => [['diagnostics' => [self::diagnostic(['message' => 'boom'])]], 'Unknown key "message"'];
        yield 'missing diagnostic code' => [['diagnostics' => [self::diagnostic(['code' => null])]], 'non-empty diagnostic code'];
        yield 'numeric diagnostic code' => [['diagnostics' => [self::diagnostic(['code' => 7])]], 'non-empty diagnostic code'];
        yield 'missing diagnostic severity' => [['diagnostics' => [self::diagnostic(['severity' => null])]], 'must be one of "error", "warning", "information", "hint"'];
        yield 'unknown diagnostic severity' => [['diagnostics' => [self::diagnostic(['severity' => 'fatal'])]], 'must be one of "error", "warning", "information", "hint"'];
        yield 'numeric diagnostic severity' => [['diagnostics' => [self::diagnostic(['severity' => 1])]], 'must be one of "error", "warning", "information", "hint"'];
        yield 'uppercase diagnostic severity' => [['diagnostics' => [self::diagnostic(['severity' => 'Error'])]], 'must be one of "error", "warning", "information", "hint"'];
        yield 'absolute diagnostic path' => [['diagnostics' => [self::diagnostic(['path' => '/etc/passwd'])]], 'relative path inside the project'];
        yield 'traversal diagnostic path' => [['diagnostics' => [self::diagnostic(['path' => '../outside.php'])]], 'relative path inside the project'];
        yield 'missing diagnostic range' => [['diagnostics' => [self::diagnostic(['range' => null])]], 'must declare a "start" and an "end"'];
        yield 'partial diagnostic range' => [['diagnostics' => [self::diagnostic(['range' => ['start' => ['line' => 1, 'character' => 0]]])]], 'must declare a "start" and an "end"'];
        yield 'inverted diagnostic range' => [['diagnostics' => [self::diagnostic(['range' => self::range(4, 2, 4, 1)])]], 'must not end before it starts'];
        yield 'negative diagnostic line' => [['diagnostics' => [self::diagnostic(['range' => self::range(-1, 0, 0, 1)])]], 'non-negative "line" and "character"'];
        yield 'float diagnostic character' => [['diagnostics' => [self::diagnostic(['range' => ['start' => ['line' => 1, 'character' => 0.5], 'end' => ['line' => 1, 'character' => 2]]])]], 'non-negative "line" and "character"'];
        yield 'extra position key' => [['diagnostics' => [self::diagnostic(['range' => ['start' => ['line' => 1, 'character' => 0, 'offset' => 3], 'end' => ['line' => 1, 'character' => 2]]])]], 'non-negative "line" and "character"'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidScenarioProvider')]
    public function testRejectsInvalidScenarios(array $overrides, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ScenarioManifestLoader())->load($this->write(self::manifest(['scenarios' => [self::scenario($overrides)]])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidScenarioProvider(): iterable
    {
        yield 'unknown key' => [['command' => 'rm -rf /'], 'Unknown key "command"'];
        yield 'missing id' => [['id' => null], 'lowercase name of at least three characters'];
        yield 'short id' => [['id' => 'ab'], 'lowercase name of at least three characters'];
        yield 'uppercase id' => [['id' => 'Route.Hover'], 'lowercase name of at least three characters'];
        yield 'missing file' => [['file' => null], 'relative path inside the project'];
        yield 'absolute file' => [['file' => '/etc/passwd'], 'relative path inside the project'];
        yield 'home file' => [['file' => '~/.ssh/id_rsa'], 'relative path inside the project'];
        yield 'traversal file' => [['file' => 'src/../../etc/passwd'], 'relative path inside the project'];
        yield 'current directory file' => [['file' => './src/Kernel.php'], 'relative path inside the project'];
        yield 'backslash file' => [['file' => 'src\\Kernel.php'], 'relative path inside the project'];
        yield 'uri file' => [['file' => 'file:///etc/passwd'], 'relative path inside the project'];
        yield 'null byte file' => [['file' => "src/Kernel.php\0.twig"], 'relative path inside the project'];
        yield 'directory file' => [['file' => 'src/'], 'relative path inside the project'];
        yield 'missing anchor' => [['anchor' => null], 'non-empty UTF-8 source excerpt'];
        yield 'empty anchor' => [['anchor' => ''], 'non-empty UTF-8 source excerpt'];
        yield 'binary anchor' => [['anchor' => "route\0"], 'non-empty UTF-8 source excerpt'];
        yield 'negative offset' => [['offset' => -1], 'byte offset within its'];
        yield 'overflowing offset' => [['offset' => 99], 'byte offset within its'];
        yield 'float offset' => [['offset' => 1.5], 'byte offset within its'];
        yield 'split character offset' => [['anchor' => 'héros', 'offset' => 2], 'character boundary of its anchor'];
        yield 'missing expect' => [['expect' => null], 'non-empty map of features'];
        yield 'empty expect' => [['expect' => []], 'non-empty map of features'];
        yield 'expect list' => [['expect' => ['hover']], 'non-empty map of features'];
        yield 'unknown feature' => [['expect' => ['symbols' => ['includes' => ['a']]]], 'Unknown feature "symbols"'];
        yield 'expectation list' => [['expect' => ['hover' => ['blog_index']]], 'must be a map of "equals"'];
        yield 'empty expectation' => [['expect' => ['hover' => []]], 'must be a map of "equals"'];
        yield 'unknown expectation key' => [['expect' => ['hover' => ['count' => 2]]], 'Unknown key "count"'];
        yield 'count expectation' => [['expect' => ['hover' => ['minCount' => 1]]], 'Unknown key "minCount"'];
        yield 'excludes only' => [['expect' => ['hover' => ['excludes' => ['blog_index']]]], 'must declare "equals" or a non-empty "includes"'];
        yield 'empty includes' => [['expect' => ['hover' => ['includes' => []]]], 'must declare "equals" or a non-empty "includes"'];
        yield 'equals with includes' => [['expect' => ['hover' => ['equals' => ['a'], 'includes' => ['a']]]], 'must not combine "equals"'];
        yield 'equals with excludes' => [['expect' => ['hover' => ['equals' => ['a'], 'excludes' => ['b']]]], 'must not combine "equals"'];
        yield 'result map' => [['expect' => ['hover' => ['includes' => ['first' => 'a']]]], 'must be a list of strings'];
        yield 'non-string result' => [['expect' => ['hover' => ['includes' => [42]]]], 'list of non-empty UTF-8 strings'];
        yield 'empty result' => [['expect' => ['hover' => ['includes' => ['']]]], 'list of non-empty UTF-8 strings'];
        yield 'repeated include' => [['expect' => ['hover' => ['includes' => ['a', 'a']]]], 'must not repeat a result'];
        yield 'rename without new name' => [['expect' => ['rename' => ['includes' => ['a']]]], 'must declare "newName" if and only if'];
        yield 'new name without rename' => [['newName' => 'blog_archive'], 'must declare "newName" if and only if'];
        yield 'empty new name' => [['expect' => ['rename' => ['includes' => ['a']]], 'newName' => ''], 'must be a non-empty string'];
        yield 'blank new name' => [['expect' => ['rename' => ['includes' => ['a']]], 'newName' => "\0"], 'must be a non-empty string'];
        yield 'edit list' => [['edit' => ['before', 'after']], 'must be an object'];
        yield 'unknown edit key' => [['edit' => self::edit(['revert' => false])], 'Unknown key "revert"'];
        yield 'missing edit before' => [['edit' => self::edit(['before' => null])], 'non-empty UTF-8 string of at most'];
        yield 'empty edit before' => [['edit' => self::edit(['before' => ''])], 'non-empty UTF-8 string of at most'];
        yield 'huge edit before' => [['edit' => self::edit(['before' => str_repeat('a', 2001)])], 'non-empty UTF-8 string of at most'];
        yield 'missing edit after' => [['edit' => self::edit(['after' => null])], '"after" in the edit'];
        yield 'binary edit after' => [['edit' => self::edit(['after' => "\0"])], '"after" in the edit'];
        yield 'unchanged edit' => [['edit' => self::edit(['after' => "'blog_index'"])], 'must differ'];
        yield 'missing edit expect' => [['edit' => self::edit(['expect' => null])], 'non-empty map of features'];
        yield 'absolute edit file' => [['edit' => self::edit(['file' => '/etc/passwd'])], 'relative path inside the project'];
        yield 'edit offset without anchor' => [['edit' => self::edit(['offset' => 2])], '"offset" in the edit of scenario "route.hover"'];
        yield 'split edit offset' => [['edit' => self::edit(['anchor' => 'héros', 'offset' => 2])], 'character boundary of its anchor'];
        yield 'apply without after fix' => [['edit' => self::edit(['applyCodeAction' => 'Create route'])], 'must declare "afterFix" if and only if'];
        yield 'after fix without apply' => [['edit' => self::edit(['afterFix' => ['diagnostics' => ['equals' => []]]])], 'must declare "afterFix" if and only if'];
        yield 'empty apply title' => [['edit' => self::edit(['applyCodeAction' => '', 'afterFix' => ['diagnostics' => ['equals' => []]]])], 'exact non-empty title'];
        yield 'invalid after fix' => [['edit' => self::edit(['applyCodeAction' => 'Create route', 'afterFix' => ['diagnostics' => ['excludes' => ['a']]]])], 'must declare "equals" or a non-empty "includes"'];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function manifest(array $overrides = []): array
    {
        return self::merge([
            'version' => 1,
            'revision' => self::REVISION,
            'scenarios' => [self::scenario()],
            'diagnostics' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function scenario(array $overrides = []): array
    {
        return self::merge([
            'id' => 'route.hover',
            'file' => 'src/Controller/BlogController.php',
            'anchor' => "redirectToRoute('blog_index'",
            'expect' => ['hover' => ['includes' => ['blog_index']]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function edit(array $overrides = []): array
    {
        return self::merge([
            'before' => "'blog_index'",
            'after' => "'blog_missing'",
            'expect' => ['diagnostics' => ['includes' => ['unknown route']]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function diagnostic(array $overrides = []): array
    {
        return self::merge([
            'path' => 'src/Controller/BlogController.php',
            'code' => 'symfony.route.unknown',
            'severity' => 'error',
            'range' => self::range(3, 8, 3, 20),
        ], $overrides);
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private static function range(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return [
            'start' => ['line' => $startLine, 'character' => $startCharacter],
            'end' => ['line' => $endLine, 'character' => $endCharacter],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function merge(array $data, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (null === $value) {
                unset($data[$key]);

                continue;
            }
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|list<string> $data
     */
    private function write(array $data): string
    {
        $path = Path::join($this->directory, 'symfony-demo.json');
        file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
