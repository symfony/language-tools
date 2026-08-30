<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttribute
{
    /**
     * @param list<PhpArgument>        $arguments
     * @param list<PhpAttributeTarget> $targets
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly array $targets,
    ) {
    }

    public function argument(string|int $name): ?PhpArgument
    {
        if (\is_int($name)) {
            return $this->arguments[$name] ?? null;
        }

        foreach ($this->arguments as $argument) {
            if ($name === $argument->name) {
                return $argument;
            }
        }

        return null;
    }
}
