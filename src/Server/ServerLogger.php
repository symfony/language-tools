<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\TrafficLoggerInterface;
use Symfony\Component\Filesystem\Path;

final class ServerLogger implements TrafficLoggerInterface
{
    private const MAX_TRACE_FRAMES = 20;

    private string $trace = 'off';

    public function __construct(
        private readonly ?WritableStream $output,
        private readonly SensitiveDataRedactor $redactor,
    ) {
    }

    public function configure(string $trace): void
    {
        if (\in_array($trace, ['off', 'messages', 'verbose'], true)) {
            $this->trace = $trace;
        }
    }

    public function isVerbose(): bool
    {
        return 'verbose' === $this->trace;
    }

    /** @param list<string> $roots */
    public function verbose(string $message, array $roots = []): void
    {
        if ($this->isVerbose()) {
            $this->write('[debug] '.$this->redactor->redact($message, $roots)."\n");
        }
    }

    public function logInbound(string $line): void
    {
        $this->traffic('inbound', $line);
    }

    public function logOutbound(string $line): void
    {
        $this->traffic('outbound', $line);
    }

    public function error(\Throwable $error): void
    {
        $message = $this->redactor->redact($error->getMessage());
        if ($this->isVerbose()) {
            foreach ($this->trace($error) as $line) {
                $message .= "\n".$this->redactor->redact($line);
            }
        }
        $this->write('[error] '.$message."\n");
    }

    public function fatal(\Throwable $error): void
    {
        $this->write(\sprintf(
            "Symfony Language Tools failed: %s at %s:%d: %s\n",
            $error::class,
            $this->relativeFile($error->getFile()),
            $error->getLine(),
            $this->redactor->redact($error->getMessage()),
        ));
    }

    /** @return list<string> */
    private function trace(\Throwable $error): array
    {
        $trace = $error->getTrace();
        $lines = [];
        foreach (\array_slice($trace, 0, self::MAX_TRACE_FRAMES) as $index => $frame) {
            $location = \is_string($frame['file'] ?? null)
                ? $this->relativeFile($frame['file']).(\is_int($frame['line'] ?? null) ? ':'.$frame['line'] : '')
                : '[internal function]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()';
            $lines[] = '#'.$index.' '.$location.' '.$call;
        }
        if (\count($trace) > self::MAX_TRACE_FRAMES) {
            $lines[] = \sprintf('... %d more frames', \count($trace) - self::MAX_TRACE_FRAMES);
        }

        return $lines;
    }

    private function traffic(string $direction, string $line): void
    {
        if ('off' === $this->trace) {
            return;
        }

        try {
            $payload = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $line = json_encode($this->redact($payload), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            $line = '[unparseable payload]';
        }
        $this->write('['.$direction.'] '.$line."\n");
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if (null !== $key && (\in_array(strtolower($key), ['text', 'value', 'phpcommand'], true) || preg_match('/password|passwd|secret|token|authorization|credential|cookie|api.?key|private.?key/i', $key))) {
            return '[redacted]';
        }
        if (!\is_array($value)) {
            return \is_string($value) ? $this->redactor->redact($value) : $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $child) {
            $redacted[$childKey] = $this->redact($child, \is_string($childKey) ? $childKey : null);
        }

        return $redacted;
    }

    private function relativeFile(string $file): string
    {
        $root = \dirname(__DIR__, 2);

        return Path::isBasePath($root, $file) && Path::canonicalize($root) !== Path::canonicalize($file)
            ? Path::makeRelative($file, $root)
            : Path::canonicalize($file);
    }

    private function write(string $message): void
    {
        try {
            if (null !== $this->output && $this->output->isWritable()) {
                $this->output->write($message);
            }
        } catch (\Throwable) {
        }
    }
}
