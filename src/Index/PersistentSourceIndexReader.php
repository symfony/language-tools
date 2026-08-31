<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexReader implements SourceIndexReaderInterface
{
    /**
     * @param ?resource                          $handle
     * @param array<string, SourceIndexMetadata> $metadata
     * @param array<string, array{int, int}>     $offsets
     */
    public function __construct(
        private $handle,
        private readonly array $metadata,
        private readonly array $offsets,
        private readonly SourceIndexJsonLinesCodec $codec,
    ) {
    }

    public function hasRecords(): bool
    {
        return [] !== $this->metadata;
    }

    public function records(): iterable
    {
        if (null === $this->handle) {
            return;
        }
        if (!rewind($this->handle) || false === $header = fgets($this->handle)) {
            throw new \UnexpectedValueException('The source index is unreadable.');
        }

        $offset = \strlen($header);
        while (false !== ($line = fgets($this->handle))) {
            $length = \strlen($line);
            $record = $this->codec->decodeRecord($line);
            if (null === $record) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }
            $relativePath = $record['path'];
            if (($this->offsets[$relativePath] ?? null) !== [$offset, $length]) {
                $offset += $length;
                continue;
            }
            $metadata = $this->metadata[$relativePath] ?? null;
            if (null === $metadata || null === $record['metadata'] || null === $record['payloads']) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }

            yield $relativePath => ['metadata' => $metadata, 'payloads' => $record['payloads']];
            $offset += $length;
        }
    }

    public function close(): void
    {
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
    }
}
