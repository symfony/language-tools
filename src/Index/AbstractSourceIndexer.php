<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Project\Project;

/** @template TFacts of SourceFactsInterface */
abstract class AbstractSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<TFacts>> */
    private array $facts = [];

    final public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    /** @return TFacts|null */
    final public function index(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        $facts = $this->extract($project, $document);
        if (null !== $facts) {
            $this->facts[$project->rootPath()][] = $facts;
        }

        return $facts;
    }

    final public function restore(Project $project, mixed $data): void
    {
        if (null === $data) {
            return;
        }
        $class = $this->factsClass();
        if (!$data instanceof $class) {
            throw new \UnexpectedValueException(\sprintf('The cached source facts for provider "%s" are invalid.', $this->name()));
        }
        $this->facts[$project->rootPath()][] = $data;
    }

    final public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->sourceIndex($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    /** @return TFacts|null */
    final public function replace(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        $facts = $this->extract($project, $document);
        if (null === $facts) {
            $this->sourceIndex($project)->removeSource($document->uri());
        } else {
            $this->sourceIndex($project)->replaceSource($facts);
        }

        return $facts;
    }

    final public function remove(Project $project, string $uri): void
    {
        $this->sourceIndex($project)->removeSource($uri);
    }

    final public function overlay(Project $project, Document $document): void
    {
        if (!$this->supportsOverlay($project, $document)) {
            return;
        }
        $facts = $this->extract($project, new SourceDocument($document->uri(), $document->languageId(), $document->text()));
        if (null !== $facts) {
            $this->sourceIndex($project)->overlay($facts);
        }
    }

    final public function removeOverlay(Project $project, string $uri): void
    {
        $this->sourceIndex($project)->removeOverlay($uri);
    }

    /** @return class-string<TFacts> */
    abstract protected function factsClass(): string;

    /** @return SourceFactsIndexInterface<TFacts> */
    abstract protected function sourceIndex(Project $project): SourceFactsIndexInterface;

    /** @return TFacts|null */
    abstract protected function extract(Project $project, SourceDocument $document): ?SourceFactsInterface;

    protected function supportsOverlay(Project $project, Document $document): bool
    {
        return true;
    }
}
