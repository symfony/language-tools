<?php

namespace Symfony\Lsp\Index;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class SourceIndexJsonLinesCodec
{
    private const SCHEMA_VERSION = 8;

    public function __construct(private readonly string $serverVersion)
    {
    }

    public function encodeHeader(): string
    {
        return json_encode(
            ['schemaVersion' => self::SCHEMA_VERSION, 'serverVersion' => $this->serverVersion],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    public function validHeader(string $line): bool
    {
        $header = json_decode($line, true);

        return \is_array($header)
            && self::SCHEMA_VERSION === ($header['schemaVersion'] ?? null)
            && $this->serverVersion === ($header['serverVersion'] ?? null);
    }

    /**
     * @param SourceIndexMetadata   $metadata
     * @param array<string, string> $payloads
     */
    public function encodeRecord(string $relativePath, array $metadata, array $payloads): string
    {
        return json_encode(
            ['path' => $relativePath, ...$metadata, 'providers' => (object) $payloads],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    public function encodeDeletion(string $relativePath): string
    {
        return json_encode(
            ['path' => $relativePath, 'deleted' => true],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    /**
     * @return ?array{path: string, metadata: ?SourceIndexMetadata}
     */
    public function decodeMetadata(string $line): ?array
    {
        $record = $this->decodeStructure($line);
        if (null === $record) {
            return null;
        }

        return ['path' => $record['path'], 'metadata' => $record['metadata']];
    }

    /**
     * @return ?array{path: string, metadata: ?SourceIndexMetadata, payloads: ?array<string, string>}
     */
    public function decodeRecord(string $line): ?array
    {
        $record = $this->decodeStructure($line);
        if (null === $record) {
            return null;
        }
        if (null === $record['metadata']) {
            return ['path' => $record['path'], 'metadata' => null, 'payloads' => null];
        }

        $payloads = [];
        foreach ($record['providers'] as $name => $payload) {
            if (!\is_string($name) || !\is_string($payload)) {
                throw new \UnexpectedValueException('A source index provider payload is invalid.');
            }
            $payloads[$name] = $payload;
        }

        return ['path' => $record['path'], 'metadata' => $record['metadata'], 'payloads' => $payloads];
    }

    /**
     * @return ?array{path: string, metadata: ?SourceIndexMetadata, providers: array<array-key, mixed>}
     */
    private function decodeStructure(string $line): ?array
    {
        if (!str_ends_with($line, "\n")) {
            return null;
        }
        $record = json_decode($line, true);
        if (!\is_array($record) || !\is_string($record['path'] ?? null)) {
            return null;
        }
        $path = $record['path'];
        if (true === ($record['deleted'] ?? null)) {
            return ['path' => $path, 'metadata' => null, 'providers' => []];
        }

        $runtimeStructure = $record['runtimeStructure'] ?? null;
        $providers = $record['providers'] ?? null;
        if (!\is_int($record['size'] ?? null)
            || !\is_int($record['modifiedAt'] ?? null)
            || !\is_string($record['hash'] ?? null)
            || 64 !== \strlen($record['hash'])
            || !\is_string($record['languageId'] ?? null)
            || (null !== $runtimeStructure && !\is_string($runtimeStructure))
            || !\is_array($providers)
        ) {
            return null;
        }

        return [
            'path' => $path,
            'metadata' => [
                'size' => $record['size'],
                'modifiedAt' => $record['modifiedAt'],
                'hash' => $record['hash'],
                'languageId' => $record['languageId'],
                'runtimeStructure' => $runtimeStructure,
            ],
            'providers' => $providers,
        ];
    }
}
