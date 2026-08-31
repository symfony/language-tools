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
}
