<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class DependencyInjectionDocumentExtractor
{
    public function __construct(
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly XmlDependencyInjectionExtractor $xmlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function extractForIndexing(string $uri, string $languageId, string $text): ?DependencyInjectionSourceFacts
    {
        return $this->extract($uri, $languageId, $text, false);
    }

    public function extractForInteractive(string $uri, string $languageId, string $text, ?string $environment = null): ?DependencyInjectionSourceFacts
    {
        return $this->extract($uri, $languageId, $text, true, $environment);
    }

    private function extract(string $uri, string $languageId, string $text, bool $interactive, ?string $environment = null): ?DependencyInjectionSourceFacts
    {
        if (!\in_array($languageId, $interactive ? ['php', 'yaml'] : ['php', 'xml', 'yaml'], true)) {
            return null;
        }

        if ('yaml' === $languageId) {
            return $this->yamlExtractor->extract($uri, $text, $environment);
        }
        if ('xml' === $languageId) {
            return $this->xmlExtractor->extract($uri, $text);
        }

        return new DependencyInjectionSourceFacts(
            $uri,
            references: $this->autowireExtractor->extract($uri, $text),
            classes: $this->classExtractor->extract($uri, $text),
        );
    }
}
