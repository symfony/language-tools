<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class InProcessLanguageServerHarnessTest extends TestCase
{
    public function testRunsAStructuredTranscriptAndExposesRawAndDecodedMessages(): void
    {
        $transcript = (new InProcessLanguageServerHarness())->run([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            new ProtocolMessageExpectation('the initialize response', static fn (array $message): bool => 1 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown', 'params' => []],
            new ProtocolMessageExpectation('the shutdown response', static fn (array $message): bool => 2 === ($message['id'] ?? null)),
            ['jsonrpc' => '2.0', 'method' => 'exit', 'params' => []],
        ]);

        self::assertSame(0, $transcript->exitCode);
        self::assertStringStartsWith('Content-Length: ', $transcript->raw);
        self::assertSame([1, 2], array_column($transcript->messages, 'id'));
    }
}
