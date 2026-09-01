<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlScalar
{
    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     */
    public function __construct(
        public readonly string $value,
        public readonly string $raw,
        public readonly int $startByte,
        public readonly int $endByte,
        public readonly int $contentStartByte,
        public readonly int $contentEndByte,
        public readonly YamlScalarStyle $style,
        public readonly array $path,
        public readonly array $sequence,
        public readonly ?string $environment,
        public readonly ?string $tag,
        public readonly ?int $tagStartByte = null,
        public readonly ?int $tagEndByte = null,
    ) {
    }

    public function isSequenceItem(): bool
    {
        return [] !== $this->sequence;
    }
}
