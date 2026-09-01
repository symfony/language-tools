<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexReader implements SourceIndexReaderInterface
{
    /** @var array<int, array{path: string, length: int}> */
    private array $recordsByOffset = [];

    /**
     * @param ?resource                          $handle
     * @param array<string, SourceIndexMetadata> $metadata
     * @param array<string, array{int, int}>     $offsets
     */
    public function __construct(
        private $handle,
        private readonly array $metadata,
        array $offsets,
        private readonly SourceIndexJsonLinesCodec $codec,
    ) {
        foreach ($offsets as $relativePath => [$offset, $length]) {
            $this->recordsByOffset[$offset] = ['path' => $relativePath, 'length' => $length];
        }
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
            throw new InvalidSourceIndexEntry('The source index is unreadable.');
        }

        $offset = \strlen($header);
        while (false !== ($line = fgets($this->handle))) {
            $length = \strlen($line);
            $expected = $this->recordsByOffset[$offset] ?? null;
            if (null === $expected) {
                $offset += $length;

                continue;
            }
            if ($expected['length'] !== $length) {
                throw new InvalidSourceIndexEntry('The source index record is corrupted.');
            }
            try {
                $record = $this->codec->decodeRecord($line);
            } catch (\UnexpectedValueException $error) {
                throw new InvalidSourceIndexEntry(previous: $error);
            }
            $relativePath = $expected['path'];
            $metadata = $this->metadata[$relativePath] ?? null;
            if (null === $record || $record['path'] !== $relativePath || null === $metadata || null === $record['metadata'] || null === $record['payloads']) {
                throw new InvalidSourceIndexEntry('The source index record is corrupted.');
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
