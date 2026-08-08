<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class DependencyInjectionSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<DependencyInjectionSourceFacts>> */
    private array $sources = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $indexes,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function name(): string
    {
        return 'dependencyInjection';
    }

    public function begin(Project $project): void
    {
        $this->sources[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        $facts = $this->extract($document->uri(), $document->languageId(), $document->text());
        if (null !== $facts) {
            $this->sources[$project->rootPath()][] = $facts;
        }

        return $facts;
    }

    public function restore(Project $project, mixed $data): void
    {
        if (null === $data) {
            return;
        }
        if (!$data instanceof DependencyInjectionSourceFacts) {
            throw new \UnexpectedValueException('The cached dependency injection source facts are invalid.');
        }

        $this->sources[$project->rootPath()][] = $data;
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->sources[$key]);
        unset($this->sources[$key]);
    }

    public function replace(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        $facts = $this->extract($document->uri(), $document->languageId(), $document->text());
        if (null === $facts) {
            $this->indexes->forProject($project)->removeSource($document->uri());
        } else {
            $this->indexes->forProject($project)->replaceSource($facts);
        }

        return $facts;
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

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $facts = $this->extract($document->uri(), $document->languageId(), $document->text());
        if (null !== $facts) {
            $this->indexes->forProject($project)->overlay($facts);
        }
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function extract(string $uri, string $languageId, string $text): ?DependencyInjectionSourceFacts
    {
        if ('yaml' === $languageId) {
            return $this->yamlExtractor->extract($uri, $text);
        }
        if ('php' !== $languageId) {
            return null;
        }

        return new DependencyInjectionSourceFacts(
            $uri,
            references: $this->autowireExtractor->extract($uri, $text),
            classes: $this->classExtractor->extract($uri, $text),
        );
    }
}
