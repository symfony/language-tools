<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TemplateCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $text = 'twig' === $request->document->languageId() ? $this->commentParser->mask($request->document->text()) : $request->document->text();
        $context = TemplateCompletionContext::create($request->document->languageId(), $text, $request->position, $this->converter);
        if (null === $context) {
            return null;
        }

        return array_map(fn (TemplateDeclaration $template): array => [
            'label' => $template->name(),
            'kind' => 17,
            'detail' => $template->uri(),
            'textEdit' => $this->protocol->textEdit($context->range(), $template->name()),
        ], $this->indexes->forProject($request->project)->matching($context->prefix()));
    }
}
