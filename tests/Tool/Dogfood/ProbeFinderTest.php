<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\Probe;
use Symfony\Lsp\Tools\Dogfood\ProbeFinder;

final class ProbeFinderTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->project);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testFindsTheAlphabeticallyFirstMatchDeterministically(): void
    {
        $this->write('src/Controller/ZebraController.php', "<?php\n\$this->redirectToRoute('zebra_route');\n");
        $this->write('src/Controller/AlphaController.php', "<?php\n\$this->redirectToRoute('alpha_route');\n");

        $probes = $this->probes(new ProbeFinder(), 'route.php');

        self::assertCount(1, $probes);
        self::assertSame('alpha_route', $probes[0]->value);
        self::assertStringEndsWith('src/Controller/AlphaController.php', $probes[0]->path);
    }

    public function testKeepsABoundedNumberOfProbesPerCategoryFromDistinctFiles(): void
    {
        $this->write('src/A.php', "<?php\n\$this->redirectToRoute('a_route');\n\$this->redirectToRoute('extra_route');\n");
        $this->write('src/B.php', "<?php\n\$this->redirectToRoute('b_route');\n");
        $this->write('src/C.php', "<?php\n\$this->redirectToRoute('c_route');\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 2), 'route.php');

        self::assertSame(['a_route', 'b_route'], array_map(static fn (Probe $probe): string => $probe->value, $probes));
    }

    public function testExcludesDependencyAndGeneratedDirectories(): void
    {
        foreach (['vendor', 'node_modules', 'var', '.git'] as $directory) {
            $this->write('src/'.$directory.'/Excluded.php', "<?php\n\$this->redirectToRoute('excluded_route');\n");
        }
        $this->write('src/Kept.php', "<?php\n\$this->redirectToRoute('kept_route');\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 10), 'route.php');

        self::assertCount(1, $probes);
        self::assertSame('kept_route', $probes[0]->value);
    }

    public function testScansConfiguredRootsInsteadOfTheDefaults(): void
    {
        $this->write('lib/Controller.php', "<?php\n\$this->redirectToRoute('lib_route');\n");

        self::assertSame([], $this->probes(new ProbeFinder(), 'route.php'));
        $probes = $this->probes(new ProbeFinder(['lib']), 'route.php');
        self::assertCount(1, $probes);
        self::assertSame('lib_route', $probes[0]->value);
    }

    public function testFindsCustomTwigCallables(): void
    {
        $this->write('src/AppExtension.php', "<?php\nnew TwigFunction('app_widget', fn () => '');\nnew TwigFilter('app_short', fn () => '');\n");
        $this->write('templates/home.html.twig', "{{ app_widget() }}\n{{ 'text'|app_short }}\n");

        $finder = new ProbeFinder();

        self::assertSame('app_widget', $this->probes($finder, 'twig.function')[0]->value);
        self::assertSame('app_short', $this->probes($finder, 'twig.filter')[0]->value);
    }

    public function testReportsThePositionInsideTheMatchedValue(): void
    {
        $this->write('src/Controller.php', "<?php\n\$this->redirectToRoute('abcd');\n");

        $probe = $this->probes(new ProbeFinder(), 'route.php')[0];

        self::assertSame(1, $probe->line);
        self::assertSame(26, $probe->character);
        self::assertSame('abcd', $probe->value);
    }

    /**
     * @return list<Probe>
     */
    private function probes(ProbeFinder $finder, string $category): array
    {
        return array_values(array_filter(
            $finder->find($this->project),
            static fn (Probe $probe): bool => $probe->category === $category,
        ));
    }

    private function write(string $relativePath, string $contents): void
    {
        (new Filesystem())->dumpFile(Path::join($this->project, $relativePath), $contents);
    }
}
