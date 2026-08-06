<?php

namespace Symfony\Lsp\Parser\TreeSitter;

use Amp\Process\Process;
use Amp\Process\ProcessException;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

final class SidecarTreeSitterParser implements TreeSitterParserInterface
{
    public function __construct(
        private readonly string $executable,
        private readonly TreeSitterResultDecoder $decoder,
    ) {
    }

    public function parse(string $language, string $source): TreeSitterTree
    {
        if (!is_file($this->executable) || !is_executable($this->executable)) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar is not executable.');
        }

        try {
            $process = Process::start([$this->executable, $language], options: ['bypass_shell' => true]);
        } catch (ProcessException $error) {
            throw new \RuntimeException('Unable to start the Symfony LSP Tree-sitter sidecar.', previous: $error);
        }

        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];

        try {
            $process->getStdin()->write($source);
            $process->getStdin()->end();
        } catch (\Throwable $error) {
            $process->kill();
            awaitAll($futures);

            throw new \RuntimeException('Unable to send source to the Symfony LSP Tree-sitter sidecar.', previous: $error);
        }

        try {
            /** @var array{stdout: string, stderr: string, exitCode: int} $result */
            $result = await($futures);
        } catch (\Throwable $error) {
            $process->kill();
            awaitAll($futures);

            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar failed.', previous: $error);
        }

        if (0 !== $result['exitCode']) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar failed: '.trim($result['stderr']));
        }

        try {
            $result = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar returned invalid JSON.', previous: $exception);
        }

        return $this->decoder->decode($result, \strlen($source));
    }
}
