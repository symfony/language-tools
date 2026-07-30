<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\ProcessResult;
use Symfony\Lsp\Runtime\ProcessRunnerInterface;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectRuntimeInitializerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        $bridgeDirectory = $this->temporaryDirectory.'/var/symfony-lsp/test';
        @unlink($bridgeDirectory.'/bridge.php');
        @rmdir($bridgeDirectory);
        @rmdir(\dirname($bridgeDirectory));
        @rmdir(\dirname($bridgeDirectory, 2));
        @unlink($this->temporaryDirectory.'/source.php');
        @rmdir($this->temporaryDirectory);
    }

    public function testExecutesBridgeAndLoadsRoutes(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => ['routes' => [
                'complete' => true,
                'items' => [
                    ['name' => 'homepage', 'path' => '/'],
                ],
            ]],
        ], \JSON_THROW_ON_ERROR), ''));
        $indexes = new RouteIndexRegistry();
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure([
            'phpCommand' => ['project-php', '--flag'],
            'environment' => 'test',
            'debug' => false,
        ]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test'),
            $processRunner,
            $indexes,
            $configuration,
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name());
        self::assertSame('project-php', $processRunner->command[0]);
        self::assertSame('--flag', $processRunner->command[1]);
        self::assertSame('--environment=test', $processRunner->command[4]);
        self::assertSame('--debug=0', $processRunner->command[5]);
        self::assertSame('--sections=routes', $processRunner->command[6]);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
    }

    public function testRejectsSnapshotSectionErrors(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test'),
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'errors' => [['section' => 'routes', 'message' => 'missing environment']],
            ], \JSON_THROW_ON_ERROR), '')),
            new RouteIndexRegistry(),
            new RuntimeConfiguration(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing environment');

        $initializer->initialize(new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        ));
    }

    public function testRejectsFailedBridgeExecution(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test'),
            new CapturingProcessRunner(new ProcessResult(1, '', 'broken container')),
            new RouteIndexRegistry(),
            new RuntimeConfiguration(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broken container');

        $initializer->initialize(new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        ));
    }
}

final class CapturingProcessRunner implements ProcessRunnerInterface
{
    /** @var non-empty-list<string> */
    public array $command = ['unset'];
    public string $workingDirectory = '';

    public function __construct(
        private readonly ProcessResult $result,
    ) {
    }

    public function run(array $command, string $workingDirectory): ProcessResult
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;

        return $this->result;
    }
}
