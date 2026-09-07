<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class DiscoverExecutableTest extends TestCase
{
    private const ROUTE = 'secret_route_name';

    private const REVISION = '0123456789abcdef0123456789abcdef01234567';

    private const ROUTE_REFERENCE = 'Symfony\\Lsp\\Feature\\Route\\RouteReference';

    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-discover-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testDocumentsThatCandidatesAreNotEvidence(): void
    {
        $result = $this->execute(['--help']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('The project is only read', $result->stdout);
        self::assertStringContainsString('never evidence that a feature works', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testRejectsARootWithoutASymfonyProject(): void
    {
        $missing = $this->execute([$this->workspace->path('missing')]);
        $this->workspace->write('library/composer.json', '{"name":"acme/library"}');
        $library = $this->execute([$this->workspace->path('library')]);

        self::assertSame(1, $missing->exitCode);
        self::assertSame('', $missing->stdout);
        self::assertStringContainsString('does not exist', $missing->stderr);
        self::assertSame(1, $library->exitCode);
        self::assertSame('', $library->stdout);
        self::assertStringContainsString('No Symfony project was discovered', $library->stderr);
    }

    public function testRejectsAScenarioManifestReachingOutsideTheProject(): void
    {
        $this->writeProject();
        $this->writeManifest([[
            'id' => 'escaping',
            'file' => '../outside.php',
            'anchor' => 'anything',
            'expect' => ['hover' => ['includes' => ['anything']]],
        ]]);

        $result = $this->execute(['--scenarios='.$this->workspace->path('manifest.json'), $this->workspace->path()]);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('must be a relative path inside the project', $result->stderr);
    }

    public function testCensusesFactsFromSourcesAloneWithoutReportingAnyIndexedValue(): void
    {
        $this->skipWithoutTreeSitter();
        $this->writeProject();

        $result = $this->execute([$this->workspace->path()]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringNotContainsString(self::ROUTE, $result->stdout);
        self::assertStringNotContainsString('AbstractController', $result->stdout);
        self::assertStringNotContainsString('unrelated statement', $result->stdout);
        // Nothing was installed or booted: only the source index was written.
        self::assertDirectoryDoesNotExist($this->workspace->path('vendor'));
        self::assertSame(['index'], array_values(array_diff((array) scandir($this->workspace->path('var/symfony-lsp/dogfood-discover')), ['.', '..'])));
        $report = json_decode($result->stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsString($report['note'] ?? null);
        self::assertStringContainsString('never that the server answers there', $report['note']);
        self::assertFalse($report['depthLimitReached']);
        self::assertArrayNotHasKey('scenarios', $report);
        $group = $this->group($report, 'routes', self::ROUTE_REFERENCE);
        self::assertSame(2, $group['count']);
        self::assertSame(2, $group['files']);
        self::assertSame(
            ['src/Controller/SecretController.php', 'templates/page.html.twig'],
            array_column($this->positions($group), 'file'),
        );
    }

    public function testReportsTheSameCensusOnAWarmAndOnACorruptedIndex(): void
    {
        $this->skipWithoutTreeSitter();
        $this->writeProject();

        $cold = $this->execute([$this->workspace->path()]);
        $warm = $this->execute([$this->workspace->path()]);
        file_put_contents($this->workspace->path('var/symfony-lsp/dogfood-discover/index/source.jsonl'), "corrupted\n");
        $rebuilt = $this->execute([$this->workspace->path()]);

        self::assertSame(0, $cold->exitCode, $cold->stderr);
        self::assertSame(0, $warm->exitCode, $warm->stderr);
        self::assertSame(0, $rebuilt->exitCode, $rebuilt->stderr);
        self::assertSame($cold->stdout, $warm->stdout);
        self::assertSame($cold->stdout, $rebuilt->stdout);
    }

    public function testFailsInsteadOfReportingAnEmptyCensusWhenNothingIsPersisted(): void
    {
        $this->skipWithoutTreeSitter();
        $this->writeProject();
        $this->workspace->write('var', 'not a directory');

        $result = $this->execute([$this->workspace->path()]);

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('No source index record was written', $result->stderr);
    }

    public function testMarksTheCandidatesAScenarioManifestAlreadyTargets(): void
    {
        $this->skipWithoutTreeSitter();
        $this->writeProject();
        $this->writeManifest([
            [
                'id' => 'route.twig',
                'file' => 'templates/page.html.twig',
                'anchor' => "path('".self::ROUTE."'",
                'offset' => 8,
                'expect' => ['definition' => ['includes' => ['SecretController.php']]],
            ],
            [
                'id' => 'without.candidate',
                'file' => 'src/Controller/SecretController.php',
                'anchor' => 'unrelated statement',
                'offset' => 4,
                'expect' => ['hover' => ['equals' => []]],
            ],
        ]);

        $result = $this->execute(['--limit=1', '--scenarios='.$this->workspace->path('manifest.json'), $this->workspace->path()]);

        self::assertSame(0, $result->exitCode, $result->stderr);
        $report = json_decode($result->stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $scenarios = $report['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        self::assertSame(['route.twig'], $scenarios['covered']);
        self::assertSame(['without.candidate'], $scenarios['uncovered']);
        $group = $this->group($report, 'routes', self::ROUTE_REFERENCE);
        self::assertSame(2, $group['count']);
        self::assertSame(1, $group['withScenario']);
        self::assertSame(['route.twig'], $group['scenarios']);
        // The covered candidate is known, so the sample keeps the other one.
        $position = $this->positions($group)[0];
        self::assertSame('src/Controller/SecretController.php', $position['file'] ?? null);
        self::assertArrayHasKey('scenario', $position);
        self::assertNull($position['scenario']);
    }

    private function writeProject(): void
    {
        $this->workspace->write('composer.json', '{"name":"acme/discover","type":"project","require":{"symfony/framework-bundle":"^7.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        $this->workspace->write('src/Controller/SecretController.php', \sprintf(<<<'PHP'
            <?php

            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\HttpFoundation\Response;
            use Symfony\Component\Routing\Attribute\Route;

            final class SecretController extends AbstractController
            {
                #[Route('/secret', name: '%1$s')]
                public function show(): Response
                {
                    // unrelated statement
                    return $this->redirectToRoute('%1$s');
                }
            }
            PHP, self::ROUTE));
        $this->workspace->write('templates/page.html.twig', \sprintf("<a href=\"{{ path('%s') }}\">link</a>\n", self::ROUTE));
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     */
    private function writeManifest(array $scenarios): void
    {
        $this->workspace->write('manifest.json', json_encode([
            'version' => 1,
            'revision' => self::REVISION,
            'scenarios' => $scenarios,
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $report
     *
     * @return array<array-key, mixed>
     */
    private function group(array $report, string $provider, string $fact): array
    {
        $groups = $report['groups'] ?? null;
        self::assertIsArray($groups);
        foreach ($groups as $group) {
            self::assertIsArray($group);
            if ($provider === ($group['provider'] ?? null) && $fact === ($group['fact'] ?? null)) {
                return $group;
            }
        }

        self::fail(\sprintf('No "%s" group for provider "%s".', $fact, $provider));
    }

    /**
     * @param array<array-key, mixed> $group
     *
     * @return list<array<array-key, mixed>>
     */
    private function positions(array $group): array
    {
        $positions = $group['positions'] ?? null;
        self::assertIsArray($positions);
        $entries = [];
        foreach ($positions as $position) {
            self::assertIsArray($position);
            $entries[] = $position;
        }

        return $entries;
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(array $arguments): ProcessResult
    {
        $command = [\PHP_BINARY];
        if (is_file($this->extension())) {
            $command = [...$command, '-d', 'extension='.$this->extension()];
        }

        return (new ExecutableRunner())->run([...$command, 'tools/dogfood-discover', ...$arguments], $this->root());
    }

    private function skipWithoutTreeSitter(): void
    {
        if (!is_file($this->extension())) {
            self::markTestSkipped('The Tree-sitter extension is not built.');
        }
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function extension(): string
    {
        return $this->root().'/var/build/tree_sitter/modules/symfony_lsp_tree_sitter.so';
    }
}
