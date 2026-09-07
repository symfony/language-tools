<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ResponseFingerprint;

final class ResponseFingerprintTest extends TestCase
{
    private const ROOT = '/workspace/project';

    private ResponseFingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new ResponseFingerprint();
    }

    public function testHashesTheSameAnswerToTheSameFingerprint(): void
    {
        $result = [['label' => 'app_home'], ['label' => 'app_login']];

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fingerprint->hash($result, self::ROOT));
        self::assertSame($this->fingerprint->hash($result, self::ROOT), $this->fingerprint->hash($result, self::ROOT));
    }

    public function testIgnoresTheOrderOfUnorderedAnswers(): void
    {
        self::assertSame(
            $this->fingerprint->hash([['label' => 'app_home'], ['label' => 'app_login']], self::ROOT),
            $this->fingerprint->hash([['label' => 'app_login'], ['label' => 'app_home']], self::ROOT),
        );
    }

    public function testIgnoresTheOrderOfObjectKeys(): void
    {
        self::assertSame(
            $this->fingerprint->hash([['label' => 'app_home', 'kind' => 12]], self::ROOT),
            $this->fingerprint->hash([['kind' => 12, 'label' => 'app_home']], self::ROOT),
        );
    }

    public function testKeepsTheOrderOfDocumentChanges(): void
    {
        $first = $this->workspaceEdit('src/A.php', 'src/B.php');
        $second = $this->workspaceEdit('src/B.php', 'src/A.php');

        self::assertNotSame($this->fingerprint->hash($first, self::ROOT), $this->fingerprint->hash($second, self::ROOT));
    }

    public function testKeepsReplacementTextApart(): void
    {
        self::assertNotSame(
            $this->fingerprint->hash([['newText' => 'app_home']], self::ROOT),
            $this->fingerprint->hash([['newText' => 'app_login']], self::ROOT),
        );
    }

    public function testNormalizesTheProjectRoot(): void
    {
        self::assertSame(
            $this->fingerprint->hash([['uri' => 'file:///workspace/project/src/A.php', 'path' => '/workspace/project/src/A.php']], self::ROOT),
            $this->fingerprint->hash([['uri' => 'file:///elsewhere/clone/src/A.php', 'path' => '/elsewhere/clone/src/A.php']], '/elsewhere/clone'),
        );
    }

    public function testIgnoresClientDrivenDocumentVersions(): void
    {
        self::assertSame(
            $this->fingerprint->hash([['uri' => 'file:///workspace/project/src/A.php', 'version' => 2]], self::ROOT),
            $this->fingerprint->hash([['uri' => 'file:///workspace/project/src/A.php', 'version' => 17]], self::ROOT),
        );
    }

    public function testSeparatesDifferentAnswers(): void
    {
        self::assertNotSame(
            $this->fingerprint->hash([['label' => 'app_home']], self::ROOT),
            $this->fingerprint->hash([['label' => 'app_home'], ['label' => 'app_login']], self::ROOT),
        );
        self::assertNotSame($this->fingerprint->hash(null, self::ROOT), $this->fingerprint->hash([], self::ROOT));
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceEdit(string $first, string $second): array
    {
        return ['documentChanges' => array_map(static fn (string $path): array => [
            'textDocument' => ['uri' => 'file:///workspace/project/'.$path, 'version' => 3],
            'edits' => [['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]], 'newText' => 'x']],
        ], [$first, $second])];
    }
}
