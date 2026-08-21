<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TranslationSourceFacts> */
final class TranslationIndex extends AbstractSourceFactsIndex
{
    /** @var list<TranslationMessage> */
    private array $runtime = [];
    private bool $complete = false;

    public function replaceRuntime(bool $complete, TranslationMessage ...$messages): void
    {
        $this->runtime = array_values($messages);
        $this->complete = $complete;
    }

    public function replaceSources(TranslationSourceFacts ...$sources): void
    {
        $this->replace(...$sources);
    }

    /** @return list<TranslationMessage> */
    public function messages(string $domain, string $key): array
    {
        return array_values(array_filter(
            $this->runtime,
            static fn (TranslationMessage $message): bool => $message->domain() === $domain && $message->key() === $key,
        ));
    }

    /** @return list<TranslationDeclaration> */
    public function declarations(string $domain, string $key): array
    {
        $result = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if ($declaration->domain() === $domain && $declaration->key() === $key) {
                    $result[] = $declaration;
                }
            }
        }

        return $result;
    }

    /** @return list<TranslationReference> */
    public function references(string $domain, string $key): array
    {
        $result = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references() as $reference) {
                if ($reference->domain() === $domain && $reference->key() === $key) {
                    $result[] = $reference;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function keys(string $domain, string $prefix): array
    {
        $keys = [];
        foreach ($this->runtime as $message) {
            if ($message->domain() === $domain && str_starts_with($message->key(), $prefix)) {
                $keys[$message->key()] = true;
            }
        }
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if ($declaration->domain() === $domain && str_starts_with($declaration->key(), $prefix)) {
                    $keys[$declaration->key()] = true;
                }
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public function domains(): array
    {
        $domains = [];
        foreach ($this->runtime as $message) {
            $domains[$message->domain()] = true;
        }
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $domains[$declaration->domain()] = true;
            }
        }
        $domains = array_keys($domains);
        sort($domains);

        return $domains;
    }

    /** @return list<string> */
    public function locales(): array
    {
        $locales = [];
        foreach ($this->runtime as $message) {
            $locales[$message->locale()] = true;
        }
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $locales[$declaration->locale()] = true;
            }
        }
        $locales = array_keys($locales);
        sort($locales);

        return $locales;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
