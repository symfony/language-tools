<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly TwigCallableMethodResolver $methods,
        private readonly TwigCallableArgumentAnalyzer $arguments,
        private readonly TwigCommentParser $comments,
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
        $before = substr($masked, 0, $offset);
        if (!$this->references->insideDirective($masked, $offset)) {
            return null;
        }
        $context = $this->arguments->incompleteCall($before);
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
            $start = $this->converter->toPosition($request->document->text, $offset - \strlen($context->prefix ?? ''));
            $items = [];
            foreach ($parameters->nameable as $name) {
                if (!str_starts_with($name, $context->prefix ?? '') || \in_array($name, $used, true)) {
                    continue;
                }
                $items[] = [
                    'label' => $name,
                    'kind' => 5,
                    'detail' => 'Twig '.$context->kind->value.' argument',
                    'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $name),
                ];
            }

            return $items;
        }
        $context = $this->arguments->callableNameCompletion($before);
        if (null === $context) {
            return null;
        }
        $start = $this->converter->toPosition($request->document->text, $offset - \strlen($context['prefix']));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->names($context['kind']) as $name) {
            if (!str_starts_with($name, $context['prefix'])) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => 3,
                'detail' => 'Twig '.$context['kind']->value,
                'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $name),
            ];
        }

        return $items;
    }
}
