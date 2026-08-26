<?php

namespace Symfony\Lsp\Tools;

final class ContentLengthProcessClient
{
    private const MAX_HEADER_BYTES = 65536;
    private const POLL_INTERVAL_MICROSECONDS = 10000;
    private const TERMINATION_GRACE_SECONDS = 1.0;

    /** @var resource|null */
    private $process;
    /** @var resource|null */
    private $input;
    /** @var resource|null */
    private $output;
    /** @var resource|null */
    private $error;
    /** @var resource|null */
    private $socket;
    private string $outputPath;
    private string $errorPath;
    private string $buffer = '';
    private int $outputOffset = 0;
    private int $errorOffset = 0;
    private string $errorOutput = '';
    /** @var list<array<string, mixed>> */
    private array $notifications = [];
    private ?int $exitCode = null;
    private bool $closed = false;

    /**
     * @param list<string>               $command
     * @param array<string, string>|null $environment
     */
    public function __construct(
        array $command,
        private float $defaultTimeout = 30.0,
        ?array $environment = null,
        bool $socketMode = false,
    ) {
        if ($defaultTimeout <= 0) {
            throw new \InvalidArgumentException('The process timeout must be greater than zero.');
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'lsp-client-out-');
        $errorPath = tempnam(sys_get_temp_dir(), 'lsp-client-err-');
        if (false === $outputPath || false === $errorPath) {
            false !== $outputPath && @unlink($outputPath);
            false !== $errorPath && @unlink($errorPath);

            throw new \RuntimeException('Unable to create the process output files.');
        }

        $process = null;
        $input = null;
        $output = null;
        $error = null;
        $socket = null;
        $listener = null;
        try {
            if ($socketMode) {
                $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
                if (false === $listener) {
                    throw new \RuntimeException('Unable to listen for the server: '.$errorMessage);
                }
                $address = (string) stream_socket_get_name($listener, false);
                $port = (int) substr($address, (int) strrpos($address, ':') + 1);
                $command[] = '--socket='.$port;
            }

            $process = proc_open(
                $command,
                [
                    ['pipe', 'r'],
                    ['file', $outputPath, 'w'],
                    ['file', $errorPath, 'w'],
                ],
                $pipes,
                env_vars: $environment,
                options: ['bypass_shell' => true],
            );
            if (!\is_resource($process)) {
                throw new \RuntimeException('Unable to start the process.');
            }
            $input = $pipes[0];
            stream_set_blocking($input, false);
            $output = fopen($outputPath, 'r');
            $error = fopen($errorPath, 'r');
            if (false === $output || false === $error) {
                throw new \RuntimeException('Unable to open the process output files.');
            }

            if (null !== $listener) {
                $socket = stream_socket_accept($listener, $defaultTimeout);
                if (false === $socket) {
                    throw new \RuntimeException(\sprintf('The process did not connect within %.3F seconds.', $defaultTimeout));
                }
                stream_set_blocking($socket, false);
            }

            $this->process = $process;
            $this->input = $input;
            $this->output = $output;
            $this->error = $error;
            $this->socket = $socket;
            $this->outputPath = $outputPath;
            $this->errorPath = $errorPath;
        } catch (\Throwable $exception) {
            \is_resource($socket) && fclose($socket);
            \is_resource($input) && fclose($input);
            \is_resource($output) && fclose($output);
            \is_resource($error) && fclose($error);
            if (\is_resource($process)) {
                proc_terminate($process);
                $deadline = microtime(true) + self::TERMINATION_GRACE_SECONDS;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(self::POLL_INTERVAL_MICROSECONDS);
                } while (microtime(true) < $deadline);
                $status['running'] && proc_terminate($process, 9);
                proc_close($process);
            }
            @unlink($outputPath);
            @unlink($errorPath);

            throw $exception;
        } finally {
            \is_resource($listener) && fclose($listener);
        }
    }

    public function pid(): int
    {
        $this->ensureOpen();
        $status = proc_get_status($this->process());

        return $status['pid'];
    }

    /** @param array<string, mixed> $message */
    public function write(array $message, ?float $timeout = null): void
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR);
        $frame = 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        $this->writeBefore($frame, microtime(true) + ($timeout ?? $this->defaultTimeout));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(int|string $id, string $method, array $params = [], ?float $timeout = null): array
    {
        $deadline = microtime(true) + ($timeout ?? $this->defaultTimeout);
        $this->writeBefore($this->encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]), $deadline);

        return $this->awaitResponseBefore($id, $deadline);
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params = [], ?float $timeout = null): void
    {
        $this->write(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params], $timeout);
    }

    /** @return array<string, mixed> */
    public function awaitResponse(int|string $id, ?float $timeout = null): array
    {
        return $this->awaitResponseBefore($id, microtime(true) + ($timeout ?? $this->defaultTimeout));
    }

    /** @return array<string, mixed> */
    public function read(?float $timeout = null): array
    {
        return $this->readBefore(
            microtime(true) + ($timeout ?? $this->defaultTimeout),
            'Timed out waiting for a Content-Length message.',
        );
    }

    /** @return list<array<string, mixed>> */
    public function notifications(): array
    {
        return $this->notifications;
    }

    public function errorOutput(): string
    {
        $this->drainError();

        return $this->errorOutput;
    }

    public function close(?float $timeout = null): int
    {
        $this->ensureOpen();
        $this->closeChannels();
        $deadline = microtime(true) + ($timeout ?? $this->defaultTimeout);
        while ($this->isRunning()) {
            $this->drainOutput();
            $this->drainError();
            if (microtime(true) >= $deadline) {
                $this->terminate();

                throw new \RuntimeException('Timed out waiting for the process to exit.'.$this->formattedErrorOutput());
            }
            $this->sleepUntil($deadline);
        }
        $this->drainOutput();
        $this->drainError();

        return $this->release();
    }

    public function terminate(): ?int
    {
        if ($this->closed) {
            return $this->exitCode;
        }

        $this->closeChannels();
        if ($this->isRunning()) {
            proc_terminate($this->process());
            $deadline = microtime(true) + self::TERMINATION_GRACE_SECONDS;
            while ($this->isRunning() && microtime(true) < $deadline) {
                $this->drainOutput();
                $this->drainError();
                $this->sleepUntil($deadline);
            }
            if ($this->isRunning()) {
                proc_terminate($this->process(), 9);
                $deadline = microtime(true) + self::TERMINATION_GRACE_SECONDS;
                while ($this->isRunning() && microtime(true) < $deadline) {
                    $this->drainOutput();
                    $this->drainError();
                    $this->sleepUntil($deadline);
                }
            }
        }
        $this->drainOutput();
        $this->drainError();

        return $this->release();
    }

    /**
     * @return array<string, mixed>
     */
    private function awaitResponseBefore(int|string $id, float $deadline): array
    {
        while (true) {
            $message = $this->readBefore($deadline, \sprintf('Timed out waiting for response %s.', $id));
            if (isset($message['method']) && \array_key_exists('id', $message)) {
                $this->writeBefore($this->encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]), $deadline);

                continue;
            }
            if (isset($message['method'])) {
                $this->notifications[] = $message;

                continue;
            }
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
    }

    /** @return array<string, mixed> */
    private function readBefore(float $deadline, string $timeoutMessage): array
    {
        $this->ensureOpen();
        while (true) {
            $message = $this->extractMessage();
            if (null !== $message) {
                return $message;
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException($timeoutMessage.$this->formattedErrorOutput());
            }

            $filled = $this->drainOutput();
            $this->drainError();
            if (!$filled && !$this->isRunning()) {
                $this->drainOutput();
                $message = $this->extractMessage();
                if (null !== $message) {
                    return $message;
                }

                throw new \RuntimeException('The process exited before sending a complete response.'.$this->formattedErrorOutput());
            }
            if (!$filled) {
                $this->sleepUntil($deadline);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function extractMessage(): ?array
    {
        $headerEnd = strpos($this->buffer, "\r\n\r\n");
        if (false === $headerEnd) {
            if (\strlen($this->buffer) > self::MAX_HEADER_BYTES) {
                throw new \RuntimeException('The Content-Length header exceeds the maximum size.');
            }

            return null;
        }

        if ($headerEnd > self::MAX_HEADER_BYTES) {
            throw new \RuntimeException('The Content-Length header exceeds the maximum size.');
        }

        $length = null;
        foreach (explode("\r\n", substr($this->buffer, 0, $headerEnd)) as $header) {
            if (1 !== preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $matches)) {
                if (!str_contains($header, ':')) {
                    throw new \RuntimeException('Invalid Content-Length response header.');
                }

                continue;
            }
            if (null !== $length) {
                throw new \RuntimeException('Duplicate Content-Length response header.');
            }
            $length = (int) $matches[1];
        }
        if (null === $length) {
            throw new \RuntimeException('Missing Content-Length response header.');
        }

        $bodyOffset = $headerEnd + 4;
        if (\strlen($this->buffer) < $bodyOffset + $length) {
            return null;
        }
        $json = substr($this->buffer, $bodyOffset, $length);
        $this->buffer = substr($this->buffer, $bodyOffset + $length);
        try {
            $message = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Invalid JSON in the Content-Length response body.'.$this->formattedErrorOutput(), 0, $exception);
        }
        if (!\is_array($message)) {
            throw new \RuntimeException('The Content-Length response body must contain a JSON object.');
        }

        return $message;
    }

    private function writeBefore(string $frame, float $deadline): void
    {
        $this->ensureOpen();
        $channel = $this->socket ?? $this->input;
        if (!\is_resource($channel)) {
            throw new \LogicException('The process input is closed.');
        }
        $offset = 0;
        while ($offset < \strlen($frame)) {
            $written = @fwrite($channel, substr($frame, $offset));
            if (false === $written) {
                throw new \RuntimeException('Unable to write to the process.'.$this->formattedErrorOutput());
            }
            if (0 < $written) {
                $offset += $written;
                continue;
            }
            $this->drainError();
            if (!$this->isRunning()) {
                throw new \RuntimeException('The process exited before accepting a complete request.'.$this->formattedErrorOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out writing to the process.'.$this->formattedErrorOutput());
            }
            $this->sleepUntil($deadline);
        }
        @fflush($channel);
    }

    /** @param array<string, mixed> $message */
    private function encode(array $message): string
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }

    private function drainOutput(): bool
    {
        if (null !== $this->socket) {
            $chunk = @fread($this->socket, 8192);
            if (false === $chunk) {
                throw new \RuntimeException('Unable to read from the process socket.'.$this->formattedErrorOutput());
            }
        } else {
            if (!\is_resource($this->output)) {
                throw new \LogicException('The process output is closed.');
            }
            fseek($this->output, $this->outputOffset);
            $chunk = (string) stream_get_contents($this->output);
            $this->outputOffset += \strlen($chunk);
        }
        if ('' === $chunk) {
            return false;
        }
        $this->buffer .= $chunk;

        return true;
    }

    private function drainError(): bool
    {
        if (!\is_resource($this->error)) {
            return false;
        }
        fseek($this->error, $this->errorOffset);
        $chunk = (string) stream_get_contents($this->error);
        if ('' === $chunk) {
            return false;
        }
        $this->errorOutput .= $chunk;
        $this->errorOffset += \strlen($chunk);

        return true;
    }

    private function isRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        if (!$status['running'] && 0 <= $status['exitcode']) {
            $this->exitCode = $status['exitcode'];
        }

        return $status['running'];
    }

    private function closeChannels(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
        if (\is_resource($this->input)) {
            fclose($this->input);
            $this->input = null;
        }
    }

    private function release(): int
    {
        if ($this->closed) {
            return $this->exitCode ?? -1;
        }
        if (\is_resource($this->output)) {
            fclose($this->output);
            $this->output = null;
        }
        if (\is_resource($this->error)) {
            fclose($this->error);
            $this->error = null;
        }
        if (\is_resource($this->process)) {
            $closedExitCode = proc_close($this->process);
            $this->process = null;
            if (0 <= $closedExitCode) {
                $this->exitCode = $closedExitCode;
            }
        }
        @unlink($this->outputPath);
        @unlink($this->errorPath);
        $this->closed = true;

        return $this->exitCode ?? -1;
    }

    /** @return resource */
    private function process()
    {
        if (!\is_resource($this->process)) {
            throw new \LogicException('The process is closed.');
        }

        return $this->process;
    }

    private function ensureOpen(): void
    {
        if ($this->closed || !\is_resource($this->process)) {
            throw new \LogicException('The process client is closed.');
        }
    }

    private function sleepUntil(float $deadline): void
    {
        $remainingMicroseconds = (int) max(0, min(self::POLL_INTERVAL_MICROSECONDS, ($deadline - microtime(true)) * 1_000_000));
        if (0 < $remainingMicroseconds) {
            usleep($remainingMicroseconds);
        }
    }

    private function formattedErrorOutput(): string
    {
        return '' === $this->errorOutput ? '' : "\nProcess error output:\n".$this->errorOutput;
    }
}
