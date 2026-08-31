<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

final class PersistentSourceIndexWriter implements SourceIndexWriterInterface
{
    /** @var array<string, array{int, int}> */
    private array $offsets = [];
    private bool $finished = false;

    /**
     * @param ?resource $handle
     */
    public function __construct(
        private readonly PersistentSourceIndexStore $store,
        private readonly SourceIndexJsonLinesCodec $codec,
        private readonly Project $project,
        private $handle,
        private readonly string $temporaryPath,
        private int $position,
    ) {
    }

    public function add(string $relativePath, array $metadata, array $payloads): void
    {
        if ($this->finished || null === $this->handle) {
            return;
        }
        $line = $this->codec->encodeRecord($relativePath, $metadata, $payloads);
        if (false === @fwrite($this->handle, $line)) {
            fclose($this->handle);
            $this->handle = null;

            return;
        }
        $this->offsets[$relativePath] = [$this->position, \strlen($line)];
        $this->position += \strlen($line);
    }

    public function commit(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
        $this->store->replaceGeneration($this->project, $this->temporaryPath, $this->offsets);
    }

    public function abort(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        @unlink($this->temporaryPath);
    }
}
