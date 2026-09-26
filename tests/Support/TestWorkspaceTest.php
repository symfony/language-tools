<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class TestWorkspaceTest extends TestCase
{
    public function testWritesFilesAndExecutablesUnderARandomRoot(): void
    {
        $workspace = new TestWorkspace();

        try {
            $file = $workspace->write('nested/file.txt', 'contents');
            $executable = $workspace->executable('bin/tool', "#!/bin/sh\n");

            self::assertSame('contents', file_get_contents($file));
            self::assertTrue(is_executable($executable));
            self::assertStringStartsWith($workspace->rootPath.'/', $file);
        } finally {
            $workspace->cleanup();
        }

        self::assertDirectoryDoesNotExist($workspace->rootPath);
    }

    public function testReadsReplacesAndRemovesFilesAndDirectories(): void
    {
        $workspace = new TestWorkspace();

        try {
            $workspace->mkdir('var/cache');
            $workspace->write('config/services.yaml', "services:\n    App\\Service: ~\n");

            self::assertDirectoryExists($workspace->path('var/cache'));
            self::assertSame("services:\n    App\\Service: ~\n", $workspace->read('config/services.yaml'));
            self::assertSame(1, $workspace->replace('config/services.yaml', 'App\\Service', 'App\\Other'));
            self::assertStringContainsString('App\\Other', $workspace->read('config/services.yaml'));

            $workspace->remove('var', 'config/services.yaml');

            self::assertDirectoryDoesNotExist($workspace->path('var'));
            self::assertFileDoesNotExist($workspace->path('config/services.yaml'));
        } finally {
            $workspace->cleanup();
        }
    }
}
