<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttribute
{
    /**
     * @param list<PhpArgument>        $arguments
     * @param list<PhpAttributeTarget> $targets
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments,
        private readonly int $startOffset,
        private readonly int $endOffset,
        private readonly int $nameStartOffset,
        private readonly int $nameEndOffset,
        private readonly array $targets,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<PhpArgument> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function endOffset(): int
    {
        return $this->endOffset;
    }

    public function nameStartOffset(): int
    {
        return $this->nameStartOffset;
    }

    public function nameEndOffset(): int
    {
        return $this->nameEndOffset;
    }

    /** @return list<PhpAttributeTarget> */
    public function targets(): array
    {
        return $this->targets;
    }

    public function argument(string|int $name): ?PhpArgument
    {
        if (\is_int($name)) {
            return $this->arguments[$name] ?? null;
        }

        foreach ($this->arguments as $argument) {
            if ($name === $argument->name()) {
                return $argument;
            }
        }

        return null;
    }
}
