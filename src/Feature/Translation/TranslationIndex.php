<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TranslationSourceFacts> */
final class TranslationIndex extends AbstractSourceFactsIndex
{
    /** @var list<TranslationMessage> */
    private array $runtime = [];
    private bool $complete = false;
    private bool $indexed = false;

    /** @var array<string, array<string, list<TranslationMessage>>> */
    private array $messages = [];

    /** @var array<string, array<string, list<TranslationDeclaration>>> */
    private array $declarations = [];

    /** @var array<string, array<string, list<TranslationReference>>> */
    private array $references = [];

    /** @var array<string, list<string>> */
    private array $keys = [];

    /** @var list<string> */
    private array $domains = [];

    /** @var list<string> */
    private array $locales = [];

    public function replaceRuntime(bool $complete, TranslationMessage ...$messages): void
    {
        $this->runtime = array_values($messages);
        $this->complete = $complete;
        $this->indexed = false;
    }

    public function replaceSources(TranslationSourceFacts ...$sources): void
    {
        $this->replace(...$sources);
    }

    /** @return list<TranslationMessage> */
    public function messages(string $domain, string $key): array
    {
        $this->index();

        return $this->messages[$domain][$key] ?? [];
    }

    /** @return list<TranslationDeclaration> */
    public function declarations(string $domain, string $key): array
    {
        $this->index();

        return $this->declarations[$domain][$key] ?? [];
    }

    /** @return list<TranslationReference> */
    public function references(string $domain, string $key): array
    {
        $this->index();

        return $this->references[$domain][$key] ?? [];
    }

    /** @return list<string> */
    public function keys(string $domain, string $prefix): array
    {
        $this->index();

        return array_values(array_filter(
            $this->keys[$domain] ?? [],
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    /** @return list<string> */
    public function domains(): array
    {
        $this->index();

        return $this->domains;
    }

    /** @return list<string> */
    public function locales(): array
    {
        $this->index();

        return $this->locales;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->messages = [];
        $this->declarations = [];
        $this->references = [];
        $keys = [];
        $domains = [];
        $locales = [];
        foreach ($this->runtime as $message) {
            $domain = $message->domain();
            $key = $message->key();
            $this->messages[$domain][$key][] = $message;
            $keys[$domain][$key] = true;
            $domains[$domain] = true;
            $locales[$message->locale()] = true;
        }
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $domain = $declaration->domain();
                $key = $declaration->key();
                $this->declarations[$domain][$key][] = $declaration;
                $keys[$domain][$key] = true;
                $domains[$domain] = true;
                $locales[$declaration->locale()] = true;
            }
            foreach ($facts->references() as $reference) {
                $this->references[$reference->domain()][$reference->key()][] = $reference;
            }
        }

        $this->keys = [];
        foreach ($keys as $domain => $domainKeys) {
            $this->keys[$domain] = array_keys($domainKeys);
            sort($this->keys[$domain]);
        }
        $this->domains = array_keys($domains);
        sort($this->domains);
        $this->locales = array_keys($locales);
        sort($this->locales);
        $this->indexed = true;
    }
}
