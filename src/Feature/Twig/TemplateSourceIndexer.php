<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class TemplateSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<TemplateDeclaration>> */
    private array $templates = [];
    /** @var array<string, list<TemplateReference>> */
    private array $references = [];

    public function __construct(
        private readonly TemplateIndexRegistry $indexes,
        private readonly TemplateReferenceExtractor $extractor,
    ) {
    }

    public function begin(Project $project): void
    {
        $this->templates[$project->rootPath()] = [];
        $this->references[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): void
    {
        $key = $project->rootPath();
        if (null !== $declaration = $this->declaration($project, $document->uri())) {
            $this->templates[$key][] = $declaration;
        }
        array_push(
            $this->references[$key],
            ...$this->extractor->extract($document->uri(), $document->languageId(), $document->text()),
        );
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $index = $this->indexes->forProject($project);
        $index->replaceSources(...$this->templates[$key]);
        $index->replaceReferences(...$this->references[$key]);
        unset($this->templates[$key], $this->references[$key]);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay(
            $document->uri(),
            $this->declaration($project, $document->uri()),
            $this->extractor->extract($document->uri(), $document->languageId(), $document->text()),
        );
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function declaration(Project $project, string $uri): ?TemplateDeclaration
    {
        $path = parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path) || !str_ends_with(strtolower($path), '.twig')) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/';
        $path = str_replace('\\', '/', rawurldecode($path));
        if (!str_starts_with($path, $root.'templates/')) {
            return null;
        }
        $name = substr($path, \strlen($root.'templates/'));
        if (str_starts_with($name, 'bundles/')) {
            $parts = explode('/', $name, 3);
            if (3 === \count($parts)) {
                $name = '@'.$parts[1].'/'.$parts[2];
            }
        }

        return new TemplateDeclaration($name, $uri, new Range(new Position(0, 0), new Position(0, 0)));
    }
}
