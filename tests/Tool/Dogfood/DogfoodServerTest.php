<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;

final class DogfoodServerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-server-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testCapturesLargeServerErrorOutputWithoutBlockingProtocolResponses(): void
    {
        $server = Path::join($this->directory, 'server');
        file_put_contents($server, <<<'PHP'
            #!/usr/bin/env php
            <?php

            function readMessage(): ?array
            {
                $length = null;
                while (false !== $line = fgets(STDIN)) {
                    if ("\r\n" === $line) {
                        break;
                    }
                    if (preg_match('/^Content-Length: (\d+)\r\n$/i', $line, $matches)) {
                        $length = (int) $matches[1];
                    }
                }
                if (null === $length) {
                    return null;
                }
                $json = '';
                while (strlen($json) < $length) {
                    $chunk = fread(STDIN, $length - strlen($json));
                    if (false === $chunk || '' === $chunk) {
                        return null;
                    }
                    $json .= $chunk;
                }

                return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            }

            function writeMessage(array $message): void
            {
                $json = json_encode($message, JSON_THROW_ON_ERROR);
                fwrite(STDOUT, 'Content-Length: '.strlen($json)."\r\n\r\n".$json);
                fflush(STDOUT);
            }

            fwrite(STDERR, str_repeat('x', 1000000));
            fflush(STDERR);
            while (null !== $message = readMessage()) {
                if (isset($message['id'])) {
                    $result = match ($message['method'] ?? null) {
                        'initialize' => ['serverInfo' => ['version' => 'test']],
                        'workspace/executeCommand' => [[
                            'source' => ['state' => 'ready'],
                            'runtime' => ['state' => 'ready'],
                        ]],
                        default => null,
                    };
                    writeMessage(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
                }
                if ('exit' === ($message['method'] ?? null)) {
                    break;
                }
            }
            PHP);
        chmod($server, 0755);

        $result = (new NativeProcessRunner())->run([
            \PHP_BINARY,
            Path::join(\dirname(__DIR__, 3), 'tools/dogfood-server'),
            '--index-timeout=1',
            '--request-timeout=1',
            $server,
            $this->directory,
        ], timeout: 10.0);

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        self::assertSame('', $result->errorOutput);
        $report = json_decode($result->standardOutput, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(0, $report['exitCode'] ?? null);
        self::assertIsString($report['serverError'] ?? null);
        self::assertSame(1000000, \strlen($report['serverError']));
    }
}
