<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class TemplateCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly TemplateIndexRegistry $indexes,
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
        $text = 'twig' === $document->languageId() ? $this->commentParser->mask($document->text()) : $document->text();
        $context = TemplateCompletionContext::create($document->languageId(), $text, $position, $this->converter);
        if (null === $context) {
            return null;
        }

        return array_map(static fn (TemplateDeclaration $template): array => [
            'label' => $template->name(),
            'kind' => 17,
            'detail' => $template->uri(),
            'textEdit' => [
                'range' => [
                    'start' => ['line' => $context->range()->start()->line(), 'character' => $context->range()->start()->character()],
                    'end' => ['line' => $context->range()->end()->line(), 'character' => $context->range()->end()->character()],
                ],
                'newText' => $template->name(),
            ],
        ], $this->indexes->forProject($project)->matching($context->prefix()));
    }
}
