<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\SourceDocument;

final class DependencyInjectionDocumentExtractor
{
    public function __construct(
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly XmlDependencyInjectionExtractor $xmlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function extractForIndexing(SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        return $this->extract($document, false);
    }

    public function extractForInteractive(SourceDocument $document, ?string $environment = null): ?DependencyInjectionSourceFacts
    {
        return $this->extract($document, true, $environment);
    }

    private function extract(SourceDocument $document, bool $interactive, ?string $environment = null): ?DependencyInjectionSourceFacts
    {
        if (!\in_array($document->languageId, $interactive ? ['php', 'yaml'] : ['php', 'xml', 'yaml'], true)) {
            return null;
        }

        if ('yaml' === $document->languageId) {
            return $this->yamlExtractor->extract($document->uri, $document->text, $environment);
        }
        if ('xml' === $document->languageId) {
            return $this->xmlExtractor->extract($document->uri, $document->text);
        }

        return new DependencyInjectionSourceFacts(
            $document->uri,
            references: $this->autowireExtractor->extract($document->uri, $document->text),
            classes: $this->classExtractor->extract($document->uri, $document->text),
        );
    }
}
