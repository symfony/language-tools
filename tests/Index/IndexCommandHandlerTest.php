<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;

final class IndexCommandHandlerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        @rmdir($this->temporaryDirectory);
    }

    public function testManuallyRefreshesSourceAndTrustedRuntimeIndexes(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        )]);
        $statuses = new ProjectIndexStatusRegistry();
        $sourceScanner = new ApplicationSourceScanner(
            $projects,
            new DocumentStore(),
            $statuses,
        );
        $runtime = new RecordingRuntimeInitializer();
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, TrustStatus::Trusted);
        $handler = new IndexCommandHandler(
            $projects,
            $workspaceTrust,
            $sourceScanner,
            new StatusRuntimeInitializer($runtime, $statuses),
            $statuses,
        );

        $result = $handler->execute([
            'command' => IndexCommandHandler::REFRESH_COMMAND,
            'arguments' => [$project->rootUri()],
        ]);

        self::assertSame([$this->temporaryDirectory], $runtime->projects);
        self::assertSame([[
            'root' => $this->temporaryDirectory,
            'source' => ['state' => 'ready'],
            'runtime' => ['state' => 'ready'],
        ]], $result);
        self::assertSame($result, $handler->execute([
            'command' => IndexCommandHandler::STATUS_COMMAND,
        ]));
    }
}

final class RecordingRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function initialize(Project $project): void
    {
        $this->projects[] = $project->rootPath();
    }
}
