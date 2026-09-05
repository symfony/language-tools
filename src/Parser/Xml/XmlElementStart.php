<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlElementStart
{
    public readonly ?string $prefix;
    public readonly string $localName;

    /** @param list<XmlAttribute> $attributes */
    public function __construct(
        public readonly int $identity,
        public readonly ?int $parentIdentity,
        public readonly string $qualifiedName,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly array $attributes,
        public readonly bool $selfClosing,
    ) {
        $separator = strpos($qualifiedName, ':');
        $this->prefix = false === $separator ? null : substr($qualifiedName, 0, $separator);
        $this->localName = false === $separator ? $qualifiedName : substr($qualifiedName, $separator + 1);
    }

    public function attribute(string $qualifiedName): ?XmlAttribute
    {
        foreach ($this->attributes as $attribute) {
            if ($qualifiedName === $attribute->qualifiedName) {
                return $attribute;
            }
        }

        return null;
    }
}
