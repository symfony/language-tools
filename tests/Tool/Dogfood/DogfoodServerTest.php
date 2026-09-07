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

    public function testTerminatesTheServerWhenProtocolParsingFails(): void
    {
        $lockPath = Path::join($this->directory, 'server.lock');
        $server = Path::join($this->directory, 'malformed-server');
        file_put_contents($server, <<<'PHP'
            #!/usr/bin/env php
            <?php

            $lock = fopen(__DIR__.'/server.lock', 'c+');
            flock($lock, LOCK_EX);
            fwrite(STDOUT, "Content-Length: 1\r\n\r\n{");
            fflush(STDOUT);
            sleep(10);
            PHP);
        chmod($server, 0755);

        $result = (new NativeProcessRunner())->run([
            \PHP_BINARY,
            Path::join(\dirname(__DIR__, 3), 'tools/dogfood-server'),
            '--index-timeout=1',
            '--request-timeout=1',
            $server,
            $this->directory,
        ], timeout: 5.0);

        self::assertNotSame(0, $result->exitCode);
        self::assertFalse($result->timedOut, $result->errorOutput);
        $lock = fopen($lockPath, 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        } finally {
            fclose($lock);
        }
    }

    public function testCountsOnlyTheDocumentLinksCoveringTheProbe(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile(Path::join($this->directory, 'config/services.yaml'), <<<'YAML'
            imports:
                - { resource: 'services/*.yaml' }
                - { resource: 'packages/framework.yaml' }
            YAML);
        $filesystem->dumpFile(Path::join($this->directory, 'config/routes.yaml'), <<<'YAML'
            app:
                resource: 'routes/app.yaml'
            YAML);
        $filesystem->dumpFile(Path::join($this->directory, 'config/packages/framework.yaml'), "framework:\n    secret: '%env(APP_SECRET)%'\n");
        $filesystem->dumpFile(Path::join($this->directory, 'config/routes/app.yaml'), "app_home:\n    path: /\n");
        $server = Path::join($this->directory, 'server');
        file_put_contents($server, "#!/usr/bin/env php\n<?php\nrequire ".var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true).";\n".<<<'PHP'
            use Symfony\Lsp\Tools\ContentLengthMessageCodec;

            $codec = new ContentLengthMessageCodec();
            $rootUri = '';
            while (true) {
                $message = $codec->read(STDIN);
                $rootUri = $message['params']['rootUri'] ?? $rootUri;
                if (isset($message['id'])) {
                    $uri = $message['params']['textDocument']['uri'] ?? '';
                    $result = match ($message['method'] ?? null) {
                        'initialize' => ['serverInfo' => ['version' => 'test']],
                        'workspace/executeCommand' => [[
                            'source' => ['state' => 'ready'],
                            'runtime' => ['state' => 'ready'],
                        ]],
                        'textDocument/completion' => [['label' => 'services/'], ['label' => 'packages/']],
                        'textDocument/documentLink' => str_ends_with($uri, 'services.yaml')
                            ? [[
                                'range' => ['start' => ['line' => 2, 'character' => 21], 'end' => ['line' => 2, 'character' => 45]],
                                'target' => $rootUri.'/config/packages/framework.yaml',
                            ]]
                            : [[
                                'range' => ['start' => ['line' => 1, 'character' => 15], 'end' => ['line' => 1, 'character' => 30]],
                                'target' => $rootUri.'/config/routes/app.yaml',
                            ]],
                        default => null,
                    };
                    fwrite(STDOUT, $codec->encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]));
                    fflush(STDOUT);
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
            '--probes-per-category=2',
            $server,
            $this->directory,
        ], timeout: 20.0);

        self::assertSame(0, $result->exitCode, $result->errorOutput);
        $report = json_decode($result->standardOutput, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame([], $report['violations'] ?? null);
        $probes = $report['probes'] ?? null;
        self::assertIsArray($probes);
        $linkCounts = [];
        $completionCounts = [];
        foreach ($probes as $probe) {
            self::assertIsArray($probe);
            if ('import.yaml' !== ($probe['category'] ?? null)) {
                continue;
            }
            $file = $probe['file'] ?? null;
            $requests = $probe['requests'] ?? null;
            self::assertIsString($file);
            self::assertIsArray($requests);
            self::assertIsArray($requests['documentLink'] ?? null);
            self::assertIsArray($requests['completion'] ?? null);
            $linkCounts[$file] = $requests['documentLink']['resultCount'] ?? null;
            $completionCounts[$file] = $requests['completion']['resultCount'] ?? null;
        }

        self::assertSame(['config/routes.yaml' => 1, 'config/services.yaml' => 0], $linkCounts);
        self::assertSame(['config/routes.yaml' => 2, 'config/services.yaml' => 2], $completionCounts);
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
        self::assertNull($report['runtimeBridgeTimings'] ?? null);
        self::assertIsString($report['serverError'] ?? null);
        self::assertSame(1000000, \strlen($report['serverError']));
        $timings = $report['timings'] ?? null;
        self::assertIsArray($timings);
        self::assertSame([
            'startupMilliseconds',
            'initializeMilliseconds',
            'sourceIndexMilliseconds',
            'runtimeIndexMilliseconds',
            'indexWaitMilliseconds',
            'probeDiscoveryMilliseconds',
            'requestsMilliseconds',
            'shutdownMilliseconds',
            'totalMilliseconds',
        ], array_keys($timings));
        foreach ($timings as $milliseconds) {
            self::assertTrue(\is_int($milliseconds) || \is_float($milliseconds));
            self::assertGreaterThanOrEqual(0.0, (float) $milliseconds);
        }
    }
}
