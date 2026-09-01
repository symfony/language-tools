<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\CapturingWritableStream;

final class ServerLoggerTest extends TestCase
{
    public function testRedactsEnvironmentAssignmentsCredentialsAndWorkspacePaths(): void
    {
        $redactor = new SensitiveDataRedactor();

        self::assertSame(
            'Failed at ./src/Kernel.php with [redacted], [redacted]@database and authorization=[redacted]',
            $redactor->redact(
                'Failed at /workspace/src/Kernel.php with DATABASE_URL=mysql://user:pass@database, mysql://user:pass@database and Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.',
                ['/workspace'],
            ),
        );
    }

    public function testKeepsTruncatedRedactedTextValidUtf8(): void
    {
        $redacted = (new SensitiveDataRedactor())->redact(str_repeat('a', 496).'é'.str_repeat('b', 20));

        self::assertSame(1, preg_match('//u', $redacted));
        self::assertStringEndsWith('...', $redacted);
    }

    public function testReportsFatalErrorsWithLocationAndRedaction(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());

        $logger->fatal(new \RuntimeException('secret=exposed'));

        self::assertMatchesRegularExpression(
            '{^Symfony Language Tools failed: RuntimeException at tests/Server/ServerLoggerTest\.php:\d+: secret=\[redacted\]\n$}',
            $output->contents(),
        );
    }

    public function testVerboseMessagesAreGatedAndRedacted(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());

        self::assertFalse($logger->isVerbose());
        $logger->verbose('Failure at /workspace/src/Kernel.php with secret=exposed', ['/workspace']);
        self::assertSame('', $output->contents());

        $logger->configure('verbose');
        self::assertTrue($logger->isVerbose());
        $logger->verbose('Failure at /workspace/src/Kernel.php with secret=exposed', ['/workspace']);

        self::assertSame("[debug] Failure at ./src/Kernel.php with secret=[redacted]\n", $output->contents());
    }

    public function testVerboseErrorTracesOmitArguments(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());
        $logger->configure('verbose');

        try {
            $this->throwWithArgument('CANARY_TRACE_ARGUMENT');
        } catch (\Throwable $error) {
            $logger->error($error);
        }

        self::assertStringContainsString('ServerLoggerTest->throwWithArgument()', $output->contents());
        self::assertStringNotContainsString('CANARY_TRACE_ARGUMENT', $output->contents());
    }

    public function testTrafficLoggingIsDisabledByDefaultAndRecursivelyRedactsContent(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());
        $payload = json_encode([
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => 'file:///workspace/.env',
                    'text' => 'APP_SECRET=exposed',
                ],
                'initializationOptions' => [
                    'phpCommand' => ['runner', '--token', 'command-secret'],
                ],
                'token' => 'exposed',
            ],
        ], \JSON_THROW_ON_ERROR);

        $logger->logInbound($payload);
        self::assertSame('', $output->contents());

        $logger->configure('messages');
        $logger->logInbound($payload);

        self::assertStringContainsString('file:///workspace/.env', $output->contents());
        self::assertStringNotContainsString('exposed', $output->contents());
        self::assertStringNotContainsString('command-secret', $output->contents());
        self::assertSame(3, substr_count($output->contents(), '[redacted]'));
    }

    private function throwWithArgument(string $argument): never
    {
        throw new \RuntimeException('Trace failure.');
    }
}
