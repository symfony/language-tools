<?php

namespace Symfony\Lsp\Index;

final readonly class SourceFileChange
{
    private const ContentOnly = 'content_only';
    private const FactsChanged = 'facts_changed';
    private const Unchanged = 'unchanged';
    private const Untracked = 'untracked';

    /** @param list<string> $domains */
    private function __construct(private string $kind, private array $domains = [])
    {
    }

    public static function contentOnly(): self
    {
        return new self(self::ContentOnly);
    }

    /** @param list<string> $domains */
    public static function factsChanged(array $domains): self
    {
        return new self(self::FactsChanged, array_values(array_unique($domains)));
    }

    public static function unchanged(): self
    {
        return new self(self::Unchanged);
    }

    public static function untracked(): self
    {
        return new self(self::Untracked);
    }

    public function requiresRuntimeRefresh(): bool
    {
        return \in_array($this->kind, [self::FactsChanged, self::Untracked], true);
    }

    /** @return list<string> */
    public function domains(): array
    {
        return $this->domains;
    }
}
