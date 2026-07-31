<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\TrafficLoggerInterface;

final class ServerLogger implements TrafficLoggerInterface
{
    private string $trace = 'off';

    public function __construct(private readonly ?WritableStream $output)
    {
    }

    public function configure(string $trace): void
    {
        if (\in_array($trace, ['off', 'messages', 'verbose'], true)) {
            $this->trace = $trace;
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
        $message = $this->redactString($error->getMessage());
        if ('verbose' === $this->trace) {
            $message .= "\n".$this->redactString($error->getTraceAsString());
        }
        $this->write('[error] '.$message."\n");
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
        if (null !== $key && (\in_array(strtolower($key), ['text', 'value'], true) || preg_match('/password|passwd|secret|token|authorization|credential|cookie|api.?key|private.?key/i', $key))) {
            return '[redacted]';
        }
        if (!\is_array($value)) {
            return \is_string($value) ? $this->redactString($value) : $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $child) {
            $redacted[$childKey] = $this->redact($child, \is_string($childKey) ? $childKey : null);
        }

        return $redacted;
    }

    private function redactString(string $value): string
    {
        return preg_replace('/\b(password|passwd|secret|token|authorization|credential|cookie|api[_-]?key|private[_-]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $value) ?? '[redacted]';
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
