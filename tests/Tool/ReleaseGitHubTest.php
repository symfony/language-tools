<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ReleaseGitHubTest extends TestCase
{
    public function testCurrentMainFailuresUseOnlyTheLatestWorkflowRun(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace('symfony-lsp-release-github-');
        $workspace->mkdir('bin');
        $workspace->executable('bin/gh', <<<'BASH'
            #!/usr/bin/env bash
            printf '%s\n' '[
                {"workflowName":"PHP quality","status":"completed","conclusion":"success"},
                {"workflowName":"VS Code integration","status":"completed","conclusion":"failure"},
                {"workflowName":"PHP quality","status":"completed","conclusion":"failure"}
            ]'
            BASH);
        $php = <<<'PHP'
            $root = $argv[1];
            require $root.'/vendor/autoload.php';
            $processes = new Symfony\Lsp\Tools\ReleaseProcessRunner(new Symfony\Lsp\Tools\InteractiveProcessRunner());
            $github = new Symfony\Lsp\Tools\ReleaseGitHub($root, $processes);
            echo json_encode($github->currentMainWorkflowFailures('commit'), JSON_THROW_ON_ERROR);
            PHP;

        try {
            $result = (new ExecutableRunner())->run(
                [\PHP_BINARY, '-r', $php, $root],
                $root,
                [...getenv(), 'PATH' => $workspace->path('bin').\PATH_SEPARATOR.(getenv('PATH') ?: '')],
            );

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame('["VS Code integration"]', $result->stdout);
            self::assertSame('', $result->stderr);
        } finally {
            $workspace->cleanup();
        }
    }
}
