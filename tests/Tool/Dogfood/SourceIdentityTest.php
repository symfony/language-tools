<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\SourceIdentity;

final class SourceIdentityTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-source-identity-');
        $this->workspace->write('src/Feature/A.php', "<?php\nclass A {}\n");
        $this->workspace->write('src/B.php', "<?php\nclass B {}\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testHashesTheSourceTreeWithoutExposingIt(): void
    {
        $identity = $this->identity();

        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/D', $identity);
        self::assertSame($identity, $this->identity());
    }

    public function testChangesWhenAFileContentChanges(): void
    {
        $identity = $this->identity();
        $this->workspace->write('src/B.php', "<?php\nclass B { public function b(): void {} }\n");

        self::assertNotSame($identity, $this->identity());
    }

    public function testChangesWhenAFileMovesWithoutChangingItsContent(): void
    {
        $identity = $this->identity();
        unlink($this->workspace->path('src/Feature/A.php'));
        $this->workspace->write('src/Renamed/A.php', "<?php\nclass A {}\n");

        self::assertNotSame($identity, $this->identity());
    }

    public function testChangesWhenAFileIsAdded(): void
    {
        $identity = $this->identity();
        $this->workspace->write('src/C.php', "<?php\nclass C {}\n");

        self::assertNotSame($identity, $this->identity());
    }

    public function testIgnoresFilesThatAreNotPhpSource(): void
    {
        $identity = $this->identity();
        $this->workspace->write('src/notes.md', 'notes');
        $this->workspace->write('src/Feature/template.twig', 'hello');

        self::assertSame($identity, $this->identity());
    }

    public function testRejectsAMissingDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        SourceIdentity::of($this->workspace->path('missing'));
    }

    private function identity(): string
    {
        return SourceIdentity::of($this->workspace->path('src'));
    }
}
