<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableMethodResolver $methods,
        private readonly TwigCallableArgumentAnalyzer $arguments,
        private readonly TwigCommentParser $comments,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $masked = $this->comments->mask($request->document->text);
        $start = $this->directives->directiveStart($masked, $offset);
        if (null === $start) {
            return null;
        }
        $directive = substr($masked, $start, $offset - $start);
        $context = $this->arguments->incompleteCall($directive, $start);
        if (null !== $context) {
            $parameters = $this->methods->parameters($request->project, [
                'callable' => [
                    'kind' => $context->kind,
                    'declarations' => $this->indexes->forProject($request->project)->declarations($context->kind, $context->callee),
                ],
            ])['callable'] ?? null;
            if (null === $parameters) {
                return null;
            }
            $used = [];
            foreach ($context->arguments as $argument) {
                if (null !== $argument->name) {
                    $used[] = $argument->name;
                }
            }
            $editStart = $this->converter->toPosition($request->document->text, $offset - \strlen($context->prefix));
            $items = [];
            foreach ($parameters->nameable as $name) {
                if (!str_starts_with($name, $context->prefix) || \in_array($name, $used, true)) {
                    continue;
                }
                $items[] = [
                    'label' => $name,
                    'kind' => 5,
                    'detail' => 'Twig '.$context->kind->value.' argument',
                    'textEdit' => $this->protocol->textEdit(new Range($editStart, $request->position), $name),
                ];
            }

            return $items;
        }
        $context = $this->arguments->callableNameCompletion($directive);
        if (null === $context) {
            return null;
        }
        $editStart = $this->converter->toPosition($request->document->text, $offset - \strlen($context['prefix']));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->names($context['kind']) as $name) {
            if (!str_starts_with($name, $context['prefix'])) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => 3,
                'detail' => 'Twig '.$context['kind']->value,
                'textEdit' => $this->protocol->textEdit(new Range($editStart, $request->position), $name),
            ];
        }

        return $items;
    }
}
