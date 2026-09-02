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

    public function testVerboseErrorTracesTruncateAndRedactEachFrame(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());
        $logger->configure('verbose');
        $shortFunction = 'longTraceFrame'.str_repeat('A', 600).bin2hex(random_bytes(4));
        $function = __NAMESPACE__.'\\'.$shortFunction;
        eval('namespace '.__NAMESPACE__.'; function '.$shortFunction.'(string $argument): never { throw new \\RuntimeException("Trace failure."); }');
        if (!\function_exists($function)) {
            self::fail('The trace function was not created.');
        }

        try {
            $this->canary_secret_token_frame(\Closure::fromCallable($function), 'CANARY_TRACE_ARGUMENT');
        } catch (\Throwable $error) {
            $logger->error($error);
        }

        $contents = $output->contents();
        self::assertStringContainsString('ServerLoggerTest->[redacted]()', $contents);
        self::assertStringNotContainsString('canary_secret_token_frame', $contents);
        self::assertStringNotContainsString('CANARY_TRACE_ARGUMENT', $contents);
        foreach (preg_split('/\R/', $contents, flags: \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            self::assertLessThanOrEqual(500, \strlen($line));
        }
    }

    public function testVerboseErrorTraceNormalizesAbsoluteClosureFunctionPaths(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());
        $logger->configure('verbose');
        /** @var \Closure(): never $throw */
        $throw = require __DIR__.'/../Fixtures/Server/throwing-closure.php';

        try {
            $throw();
        } catch (\Throwable $error) {
            $logger->error($error);
        }

        $contents = $output->contents();
        self::assertStringContainsString('{closure}()', $contents);
        self::assertStringNotContainsString(\dirname(__DIR__, 2), $contents);
        self::assertStringNotContainsString(str_replace('/', '\\', \dirname(__DIR__, 2)), $contents);
    }

    public function testVerboseErrorTraceFrameCountIsBounded(): void
    {
        $output = new CapturingWritableStream();
        $logger = new ServerLogger($output, new SensitiveDataRedactor());
        $logger->configure('verbose');

        try {
            $this->throwRecursively(30);
        } catch (\Throwable $error) {
            $logger->error($error);
        }

        self::assertStringContainsString('#19 ', $output->contents());
        self::assertStringNotContainsString('#20 ', $output->contents());
        self::assertMatchesRegularExpression('/\.\.\. \d+ more frames/', $output->contents());
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

    private function canary_secret_token_frame(\Closure $function, string $argument): never
    {
        $function($argument);

        throw new \LogicException('The trace function unexpectedly returned.');
    }

    private function throwRecursively(int $remaining): never
    {
        if (0 === $remaining) {
            throw new \RuntimeException('Trace failure.');
        }

        $this->throwRecursively($remaining - 1);
    }
}
