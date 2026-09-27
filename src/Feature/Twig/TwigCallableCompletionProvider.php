<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class TwigCallableCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableSourceIndexRegistry $indexes,
        private readonly TwigCallableMethodResolver $methods,
        private readonly TwigCallableArgumentAnalyzer $arguments,
        private readonly TwigCommentParser $comments,
        private readonly TwigDirectiveLocator $directives,
        private readonly TwigCallableShapeResolver $shapes,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        if ('twig' !== $request->document->languageId) {
            return [];
        }
        $offset = $request->offset;
        $masked = $this->comments->mask($request->document->text);
        $start = $this->directives->directiveStart($masked, $offset);
        if (null === $start) {
            return [];
        }
        $directive = substr($masked, $start, $offset - $start);
        $hash = $this->arguments->hashArgument($directive);
        if (null !== $hash) {
            return $this->hashItems($request, $hash);
        }
        $context = $this->arguments->incompleteCall($directive, $start);
        if (null !== $context) {
            $parameters = $this->methods->parameters($request->project, [
                'callable' => [
                    'kind' => $context->kind,
                    'declarations' => $this->indexes->forProject($request->project)->declarations($context->kind, $context->callee),
                ],
            ])['callable'] ?? null;
            if (null === $parameters) {
                return [];
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
                $items[] = $this->protocol->completionItem(
                    $name,
                    CompletionItemKind::Field,
                    'Twig '.$context->kind->value.' argument',
                    $this->protocol->textEdit(new Range($editStart, $request->position), $name),
                );
            }

            return $items;
        }
        $context = $this->arguments->callableNameCompletion($directive);
        if (null === $context) {
            return [];
        }
        $editStart = $this->converter->toPosition($request->document->text, $offset - \strlen($context['prefix']));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->names($context['kind']) as $name) {
            if (!str_starts_with($name, $context['prefix'])) {
                continue;
            }
            $items[] = $this->protocol->completionItem(
                $name,
                CompletionItemKind::Function,
                'Twig '.$context['kind']->value,
                $this->protocol->textEdit(new Range($editStart, $request->position), $name),
            );
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function hashItems(PositionedRequest $request, TwigCallableHashArgument $hash): array
    {
        $declarations = $this->indexes->forProject($request->project)->declarations($hash->kind, $hash->callee);
        $range = new Range(
            $this->converter->toPosition($request->document->text, $request->offset - \strlen($hash->prefix)),
            $request->position,
        );
        $items = [];
        if (null !== $hash->key) {
            foreach ($this->shapes->values($request->project, $hash, $declarations) as $value) {
                if (!str_starts_with($value, $hash->prefix) || null === $hash->quote || str_contains($value, $hash->quote) || str_contains($value, '\\')) {
                    continue;
                }
                $items[] = $this->protocol->completionItem(
                    $value,
                    CompletionItemKind::Value,
                    'Twig '.$hash->kind->value.' argument value',
                    $this->protocol->textEdit($range, $value),
                );
            }

            return $items;
        }
        foreach ($this->shapes->keys($request->project, $hash, $declarations) as $key) {
            if (!str_starts_with($key->name, $hash->prefix) || \in_array($key->name, $hash->usedKeys, true)) {
                continue;
            }
            $text = $this->hashKeyText($key->name, $hash->quote);
            if (null === $text) {
                continue;
            }
            $items[] = $this->protocol->completionItem(
                $key->name,
                CompletionItemKind::Property,
                ($key->optional ? '(optional) ' : '').$key->type,
                $this->protocol->textEdit($range, $text),
            );
        }

        return $items;
    }

    private function hashKeyText(string $key, ?string $quote): ?string
    {
        if (null !== $quote) {
            return str_contains($key, $quote) || str_contains($key, '\\') ? null : $key;
        }
        if (1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
            return $key;
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $key)."'";
    }
}
