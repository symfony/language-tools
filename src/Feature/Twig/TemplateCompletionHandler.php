<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TemplateCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly CommentParserRegistry $comments,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $text = $this->comments->mask($request->document->languageId, $request->document->text);
        $context = TemplateCompletionContext::create($request->document->languageId, $text, $request->position, $this->converter);
        if (null === $context
            || ($context->phpRenderCall && !$this->extractor->supportsPhpRenderAt(
                $request->document->text,
                $this->converter->toByteOffset($request->document->text, $request->position),
                $this->classIndexes->forProject($request->project),
            ))
        ) {
            return null;
        }

        return array_map(fn (TemplateDeclaration $template): array => [
            'label' => $template->name,
            'kind' => 17,
            'detail' => $template->uri,
            'textEdit' => $this->protocol->textEdit($context->range, $template->name),
        ], $this->indexes->forProject($request->project)->matching($context->prefix));
    }
}
