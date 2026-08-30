<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclaration;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclarationParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigVariableProvider implements CompletionProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TwigComponentIndexRegistry $componentIndexes,
        private readonly TemplateNameResolver $nameResolver,
        private readonly TwigTypeDeclarationParser $typeDeclarationParser,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId || null === $template = $this->nameResolver->resolve($request->project, $request->document->uri)) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($request->document->text, $request->position);
        $before = substr($this->commentParser->mask($request->document->text), 0, $cursor);
        if (!preg_match('/(?:{{|{%)[^}\n]*?([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)?$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $prefix = $match[1][0] ?? '';
        $start = $cursor - \strlen($prefix);
        $startPosition = $this->converter->toPosition($request->document->text, $start);
        $declarations = $this->typeDeclarations($request->document);
        $items = [];
        foreach ($this->variables($request->project, $template, $declarations) as $variable) {
            if (!str_starts_with($variable, $prefix)) {
                continue;
            }
            $item = [
                'label' => $variable,
                'kind' => 6,
                'detail' => 'Symfony Twig variable',
                'textEdit' => $this->protocol->textEdit(new Range($startPosition, $request->position), $variable),
            ];
            if (isset($declarations[$variable])) {
                $declaration = $declarations[$variable];
                $item['detail'] = \sprintf(
                    'Twig variable: %s (%s)',
                    $declaration->type,
                    $declaration->optional ? 'optional' : 'required',
                );
                if (null !== $documentation = $declaration->documentation) {
                    $item['documentation'] = ['kind' => 'markdown', 'value' => $documentation];
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId || null === $template = $this->nameResolver->resolve($request->project, $request->document->uri)) {
            return null;
        }
        $name = $this->word($this->commentParser->mask($request->document->text), $request->position);
        $declarations = $this->typeDeclarations($request->document);
        if (null === $name || !\in_array($name, $this->variables($request->project, $template, $declarations), true)) {
            return null;
        }
        if (isset($declarations[$name])) {
            $declaration = $declarations[$name];
            $value = \sprintf(
                "Twig variable: `%s`\n\nDeclared type: `%s`\n\n%s template variable",
                $this->markdownCode($name),
                $this->markdownCode($declaration->type),
                $declaration->optional ? 'Optional' : 'Required',
            );
            if (null !== $documentation = $declaration->documentation) {
                $value .= "\n\n".$documentation;
            }

            return $this->protocol->markdownHover($value);
        }
        $source = $this->indexes->forProject($request->project)->isGlobal($name) ? 'Twig global' : 'render context';

        return $this->protocol->markdownHover(\sprintf(
            "Twig variable: `%s`\n\nProvided by: %s",
            $name,
            $source,
        ));
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
            if ($component->template === $template) {
                array_push($variables, ...$component->properties);
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
        foreach ($this->typeDeclarationParser->parse($document->text) as $declaration) {
            $declarations[$declaration->name] = $declaration;
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
        while ($start > 0 && 1 === preg_match('/[A-Za-z0-9_\x7f-\xff]/', $text[$start - 1])) {
            --$start;
        }
        $end = $offset;
        $length = \strlen($text);
        while ($end < $length && 1 === preg_match('/[A-Za-z0-9_\x7f-\xff]/', $text[$end])) {
            ++$end;
        }
        $word = substr($text, $start, $end - $start);

        return 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $word) ? $word : null;
    }
}
