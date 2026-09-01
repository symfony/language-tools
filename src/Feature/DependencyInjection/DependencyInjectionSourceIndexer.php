<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<DependencyInjectionSourceFacts> */
final class DependencyInjectionSourceIndexer extends AbstractSourceIndexer
{
    private readonly DependencyInjectionDocumentExtractor $extractor;

    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $indexes,
        YamlDependencyInjectionExtractor $yamlExtractor,
        XmlDependencyInjectionExtractor $xmlExtractor,
        PhpAutowireReferenceExtractor $autowireExtractor,
        PhpClassDeclarationExtractor $classExtractor,
    ) {
        $this->extractor = new DependencyInjectionDocumentExtractor(
            $yamlExtractor,
            $xmlExtractor,
            $autowireExtractor,
            $classExtractor,
        );
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
            ...$data->services,
            ...$data->parameters,
            ...$data->references,
            ...$data->classes,
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
        return $this->extractor->extractForIndexing($document);
    }
}
