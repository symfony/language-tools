<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

final class PersistentSourceIndexWriter implements SourceIndexWriterInterface
{
    private const WRITE_BUFFER_BYTES = 1048576;

    /** @var array<string, array{int, int}> */
    private array $offsets = [];
    private bool $finished = false;
    private string $buffer = '';
    private int $position = 0;

    /**
     * @param ?resource $handle
     */
    public function __construct(
        private readonly PersistentSourceIndexStore $store,
        private readonly SourceIndexJsonLinesCodec $codec,
        private readonly Project $project,
        private $handle,
        private readonly string $temporaryPath,
    ) {
        $header = $this->codec->encodeHeader();
        $this->buffer = $header;
        if ($this->flush()) {
            $this->position = \strlen($header);
        }
    }

    public function add(string $relativePath, array $metadata, array $payloads): void
    {
        if ($this->finished || null === $this->handle) {
            return;
        }
        $line = $this->codec->encodeRecord($relativePath, $metadata, $payloads);
        $length = \strlen($line);
        $this->offsets[$relativePath] = [$this->position, $length];
        $this->position += $length;
        $this->buffer .= $line;
        if (\strlen($this->buffer) >= self::WRITE_BUFFER_BYTES) {
            $this->flush();
        }
    }

    public function commit(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (!$this->flush()) {
            return;
        }
        /** @var resource $handle */
        $handle = $this->handle;
        fclose($handle);
        $this->handle = null;
        $this->store->replaceGeneration($this->project, $this->temporaryPath, $this->offsets);
    }

    public function abort(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $this->discard();
    }

    private function flush(): bool
    {
        if (null === $this->handle) {
            $this->discard();

            return false;
        }
        while ('' !== $this->buffer) {
            $written = @fwrite($this->handle, $this->buffer);
            if (false === $written || 0 === $written) {
                $this->discard();

                return false;
            }
            $this->buffer = substr($this->buffer, $written);
        }

        return true;
    }

    private function discard(): void
    {
        $this->buffer = '';
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        @unlink($this->temporaryPath);
    }
}
