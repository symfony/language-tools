<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<ConsoleSourceFacts> */
final class ConsoleSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly ConsoleSourceIndexRegistry $indexes,
        private readonly ConsoleExtractor $extractor,
    ) {
    }

    public function name(): string
    {
        return 'console';
    }

    public function payloadClasses(): array
    {
        return [ConsoleSourceFacts::class, ConsoleCommandDeclaration::class, ConsoleInputReference::class, ConsoleInputKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof ConsoleSourceFacts) {
            throw new \UnexpectedValueException('The Console source facts are invalid.');
        }

        return $data->declarations;
    }

    protected function factsClass(): string
    {
        return ConsoleSourceFacts::class;
    }

    protected function sourceIndex(Project $project): ConsoleSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): ConsoleSourceFacts
    {
        return $this->extractor->extract($document);
    }
}
