<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Project\Project;

final class TwigVariableProvider implements CompletionProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TwigComponentIndexRegistry $componentIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        if ('twig' !== $document->languageId() || null === $template = $this->template($project, $document)) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $cursor);
        if (!preg_match('/(?:{{|{%)[^}\n]*?([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $prefix = $match[1][0] ?? '';
        $start = $cursor - \strlen($prefix);
        $startPosition = $this->converter->toPosition($document->text(), $start);
        $items = [];
        foreach ($this->variables($project, $template) as $variable) {
            if (!str_starts_with($variable, $prefix)) {
                continue;
            }
            $items[] = [
                'label' => $variable,
                'kind' => 6,
                'detail' => 'Symfony Twig variable',
                'textEdit' => [
                    'range' => [
                        'start' => ['line' => $startPosition->line(), 'character' => $startPosition->character()],
                        'end' => ['line' => $position->line(), 'character' => $position->character()],
                    ],
                    'newText' => $variable,
                ],
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        if ('twig' !== $document->languageId() || null === $template = $this->template($project, $document)) {
            return null;
        }
        $name = $this->word($document, $position);
        if (null === $name || !\in_array($name, $this->variables($project, $template), true)) {
            return null;
        }
        $source = $this->indexes->forProject($project)->isGlobal($name) ? 'Twig global' : 'render context';

        return ['contents' => ['kind' => 'markdown', 'value' => \sprintf(
            "Twig variable: `%s`\n\nProvided by: %s",
            $name,
            $source,
        )]];
    }

    /** @return list<string> */
    private function variables(Project $project, string $template): array
    {
        $variables = $this->indexes->forProject($project)->variables($template);
        foreach ($this->componentIndexes->forProject($project)->components() as $component) {
            if ($component->template() === $template) {
                array_push($variables, ...$component->properties());
            }
        }
        $variables = array_values(array_unique($variables));
        sort($variables);

        return $variables;
    }

    private function template(Project $project, Document $document): ?string
    {
        $path = rawurldecode((string) parse_url($document->uri(), \PHP_URL_PATH));
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/templates/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root)) {
            return null;
        }
        $name = substr($path, \strlen($root));
        if (str_starts_with($name, 'bundles/')) {
            $parts = explode('/', $name, 3);
            if (3 === \count($parts)) {
                return '@'.$parts[1].'/'.$parts[2];
            }
        }

        return $name;
    }

    private function word(Document $document, Position $position): ?string
    {
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $start = $offset;
        while ($start > 0 && 1 === preg_match('/[A-Za-z0-9_]/', $document->text()[$start - 1])) {
            --$start;
        }
        $end = $offset;
        $length = \strlen($document->text());
        while ($end < $length && 1 === preg_match('/[A-Za-z0-9_]/', $document->text()[$end])) {
            ++$end;
        }
        $word = substr($document->text(), $start, $end - $start);

        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $word) ? $word : null;
    }
}
