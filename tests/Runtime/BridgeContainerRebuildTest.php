<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\NativeProcessRunner;

final class BridgeContainerRebuildTest extends TestCase
{
    public function testRebuildsTheContainerWithoutDeletingTheFilesOtherProcessesAreLoading(): void
    {
        $project = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        self::assertIsString($project);
        $runner = new NativeProcessRunner(30.0);
        $warmup = $runner->run($this->bridgeCommand($project, []), $project);
        self::assertSame(0, $warmup->exitCode, $warmup->stderr."\n".$warmup->stdout);
        $containerDirectories = glob($project.'/var/cache/test/Container*', \GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $containerDirectories);
        $loadedFile = $containerDirectories[0].'/symfony-lsp-loaded-by-another-process.php';
        file_put_contents($loadedFile, '<?php');

        $counter = sys_get_temp_dir().'/symfony-lsp-container-rebuild-'.bin2hex(random_bytes(8));
        $previousCounter = getenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER');
        putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER='.$counter);
        try {
            $process = $runner->run($this->bridgeCommand($project, ['--rebuild-container=1']), $project);
        } finally {
            if (false === $previousCounter) {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER');
            } else {
                putenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER='.$previousCounter);
            }
        }

        try {
            self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
            self::assertSame(["compiled\n"], file($counter));
            self::assertFileExists($loadedFile);
        } finally {
            @unlink($counter);
            @unlink($loadedFile);
        }
    }

    /**
     * @param list<string> $options
     *
     * @return non-empty-list<string>
     */
    private function bridgeCommand(string $project, array $options): array
    {
        return [
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/resources/bridge.php',
            '--project='.$project,
            '--environment=test',
            '--debug=1',
            '--sections=routes',
            ...$options,
        ];
    }
}
