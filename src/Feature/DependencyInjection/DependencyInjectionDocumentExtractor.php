<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

final class DependencyInjectionDocumentExtractor
{
    public function __construct(
        private readonly ProjectPathResolver $paths,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly XmlDependencyInjectionExtractor $xmlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function extractForIndexing(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        return $this->extract($project, $document, false);
    }

    public function extractForInteractive(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        return $this->extract($project, $document, true);
    }

    private function extract(Project $project, SourceDocument $document, bool $interactive): ?DependencyInjectionSourceFacts
    {
        if (!\in_array($document->languageId, $interactive ? ['php', 'yaml'] : ['php', 'xml', 'yaml'], true)) {
            return null;
        }

        if ('yaml' === $document->languageId) {
            if (!$this->isConfigurationFile($project, $document->uri)) {
                return null;
            }

            return $this->yamlExtractor->extract($document->uri, $document->text);
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

    private function isConfigurationFile(Project $project, string $uri): bool
    {
        $relativePath = $this->paths->relative($project, $uri);

        return null !== $relativePath
            && (null !== $project->configRelativePath($relativePath) || str_contains('/'.$relativePath, '/config/'));
    }
}
