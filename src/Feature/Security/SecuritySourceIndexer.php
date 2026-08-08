<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class SecuritySourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<SecuritySourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly SecuritySourceIndexRegistry $indexes, private readonly SecurityExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'security';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): SecuritySourceFacts
    {
        return $this->add($project, $this->extract($document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof SecuritySourceFacts) {
            throw new \UnexpectedValueException('The cached security source facts are invalid.');
        }

        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): SecuritySourceFacts
    {
        $facts = $this->extract($document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof SecuritySourceFacts) {
            throw new \UnexpectedValueException('The security source facts are invalid.');
        }

        return array_values(array_filter($data->symbols(), static fn (SecuritySourceSymbol $symbol): bool => $symbol->isDeclaration()));
    }

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay($this->extractor->extract($document->uri(), $document->languageId(), $document->text()));
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, SecuritySourceFacts $facts): SecuritySourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(SourceDocument $document): SecuritySourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
