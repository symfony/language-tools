<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<EnvironmentSourceFacts> */
final class EnvironmentSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly EnvironmentIndexRegistry $indexes, private readonly EnvironmentExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'environment';
    }

    public function payloadClasses(): array
    {
        return [EnvironmentDeclaration::class, EnvironmentReference::class, EnvironmentSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof EnvironmentSourceFacts) {
            throw new \UnexpectedValueException('The environment source facts are invalid.');
        }

        return [
            ...$data->declarations,
            ...$data->references,
        ];
    }

    protected function factsClass(): string
    {
        return EnvironmentSourceFacts::class;
    }

    protected function sourceIndex(Project $project): EnvironmentIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): EnvironmentSourceFacts
    {
        return $this->extractor->extract($document);
    }
}
