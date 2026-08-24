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
}
