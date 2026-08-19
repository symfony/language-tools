<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclaration;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclarationParser;
use Symfony\Lsp\Project\Project;

final class TwigVariableProvider implements CompletionProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TwigComponentIndexRegistry $componentIndexes,
        private readonly TemplateNameResolver $nameResolver,
        private readonly TwigTypeDeclarationParser $typeDeclarationParser,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        if ('twig' !== $document->languageId() || null === $template = $this->nameResolver->resolve($project, $document->uri())) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($this->commentParser->mask($document->text()), 0, $cursor);
        if (!preg_match('/(?:{{|{%)[^}\n]*?([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $prefix = $match[1][0] ?? '';
        $start = $cursor - \strlen($prefix);
        $startPosition = $this->converter->toPosition($document->text(), $start);
        $declarations = $this->typeDeclarations($document);
        $items = [];
        foreach ($this->variables($project, $template, $declarations) as $variable) {
            if (!str_starts_with($variable, $prefix)) {
                continue;
            }
            $item = [
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
            if (isset($declarations[$variable])) {
                $declaration = $declarations[$variable];
                $item['detail'] = \sprintf(
                    'Twig variable: %s (%s)',
                    $declaration->type(),
                    $declaration->optional() ? 'optional' : 'required',
                );
                if (null !== $documentation = $declaration->documentation()) {
                    $item['documentation'] = ['kind' => 'markdown', 'value' => $documentation];
                }
            }
            $items[] = $item;
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
        if ('twig' !== $document->languageId() || null === $template = $this->nameResolver->resolve($project, $document->uri())) {
            return null;
        }
        $name = $this->word($this->commentParser->mask($document->text()), $position);
        $declarations = $this->typeDeclarations($document);
        if (null === $name || !\in_array($name, $this->variables($project, $template, $declarations), true)) {
            return null;
        }
        if (isset($declarations[$name])) {
            $declaration = $declarations[$name];
            $value = \sprintf(
                "Twig variable: `%s`\n\nDeclared type: `%s`\n\n%s template variable",
                $this->markdownCode($name),
                $this->markdownCode($declaration->type()),
                $declaration->optional() ? 'Optional' : 'Required',
            );
            if (null !== $documentation = $declaration->documentation()) {
                $value .= "\n\n".$documentation;
            }

            return ['contents' => ['kind' => 'markdown', 'value' => $value]];
        }
        $source = $this->indexes->forProject($project)->isGlobal($name) ? 'Twig global' : 'render context';

        return ['contents' => ['kind' => 'markdown', 'value' => \sprintf(
            "Twig variable: `%s`\n\nProvided by: %s",
            $name,
            $source,
        )]];
    }

    /**
     * @param array<string, TwigTypeDeclaration> $declarations
     *
     * @return list<string>
     */
    private function variables(Project $project, string $template, array $declarations): array
    {
        $variables = $this->indexes->forProject($project)->variables($template);
        foreach ($this->componentIndexes->forProject($project)->components() as $component) {
            if ($component->template() === $template) {
                array_push($variables, ...$component->properties());
            }
        }
        array_push($variables, ...array_keys($declarations));
        $variables = array_values(array_unique($variables));
        sort($variables);

        return $variables;
    }

    /** @return array<string, TwigTypeDeclaration> */
    private function typeDeclarations(Document $document): array
    {
        $declarations = [];
        foreach ($this->typeDeclarationParser->parse($document->text()) as $declaration) {
            $declarations[$declaration->name()] = $declaration;
        }

        return $declarations;
    }

    private function markdownCode(string $value): string
    {
        return str_replace('`', '\\`', $value);
    }

    private function word(string $text, Position $position): ?string
    {
        $offset = $this->converter->toByteOffset($text, $position);
        $start = $offset;
        while ($start > 0 && 1 === preg_match('/[A-Za-z0-9_]/', $text[$start - 1])) {
            --$start;
        }
        $end = $offset;
        $length = \strlen($text);
        while ($end < $length && 1 === preg_match('/[A-Za-z0-9_]/', $text[$end])) {
            ++$end;
        }
        $word = substr($text, $start, $end - $start);

        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $word) ? $word : null;
    }
}
