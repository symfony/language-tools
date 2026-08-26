<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ServerHarness;

final class ServerHarnessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-server-harness-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @param array<string, string> $files */
    #[DataProvider('probeBudgetProvider')]
    public function testDerivesTheProcessBudgetFromDiscoveredProbes(array $files, float $expectedTimeout): void
    {
        foreach ($files as $path => $contents) {
            $absolutePath = Path::join($this->directory, $path);
            (new Filesystem())->mkdir(\dirname($absolutePath));
            file_put_contents($absolutePath, $contents);
        }
        $processes = new FakeProcessRunner(static fn (array $command, ?string $directory): ProcessResult => new ProcessResult(0, '{}', '', false));
        $configuration = new ProjectConfiguration(
            'application',
            'https://example.com/application.git',
            str_repeat('a', 40),
            null,
            'dev',
            'composer',
            false,
            20,
            requestTimeout: 3,
            probeRoots: ['src', 'templates'],
        );

        (new ServerHarness($processes, '/tools/dogfood-server', '/bin/symfony-lsp'))->run($configuration, $this->directory);

        self::assertSame($expectedTimeout, $processes->calls[0]['timeout']);
    }

    /** @return iterable<string, array{array<string, string>, float}> */
    public static function probeBudgetProvider(): iterable
    {
        yield 'no probes' => [[], 41.0];
        yield 'two probes with nine requests each' => [[
            'src/Controller.php' => "<?php\n\$router->generate('home');\n",
            'templates/home.html.twig' => "{{ path('home') }}\n",
        ], 95.0];
    }
}
