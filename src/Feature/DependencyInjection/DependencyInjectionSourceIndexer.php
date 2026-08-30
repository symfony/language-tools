<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<DependencyInjectionSourceFacts> */
final class DependencyInjectionSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $indexes,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly XmlDependencyInjectionExtractor $xmlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function name(): string
    {
        return 'dependencyInjection';
    }

    public function payloadClasses(): array
    {
        return [
            DependencyInjectionReference::class,
            DependencyInjectionSourceFacts::class,
            DependencyInjectionSymbol::class,
            DependencyInjectionSymbolKind::class,
            ParameterDeclaration::class,
            PhpClassDeclaration::class,
            ServiceDeclaration::class,
        ];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (null === $data) {
            return [];
        }
        if (!$data instanceof DependencyInjectionSourceFacts) {
            throw new \UnexpectedValueException('The dependency injection source facts are invalid.');
        }

        return [
            ...$data->services(),
            ...$data->parameters(),
            ...$data->references(),
            ...$data->classes(),
        ];
    }

    protected function factsClass(): string
    {
        return DependencyInjectionSourceFacts::class;
    }

    protected function sourceIndex(Project $project): DependencyInjectionSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        if ('yaml' === $document->languageId) {
            return $this->yamlExtractor->extract($document->uri, $document->text);
        }
        if ('xml' === $document->languageId) {
            return $this->xmlExtractor->extract($document->uri, $document->text);
        }
        if ('php' !== $document->languageId) {
            return null;
        }

        return new DependencyInjectionSourceFacts(
            $document->uri,
            references: $this->autowireExtractor->extract($document->uri, $document->text),
            classes: $this->classExtractor->extract($document->uri, $document->text),
        );
    }
}
