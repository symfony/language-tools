<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class TemplateCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TemplateIndexRegistry $indexes,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly CommentParserRegistry $comments,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $document = $request->document;
        $context = match ($document->languageId) {
            'php' => $this->extractor->phpCompletionAt(
                $document->text,
                $request->offset,
                $this->classIndexes->forProject($request->project),
            ),
            'twig' => $this->twigContext($this->comments->mask('twig', $document->text), $request->position),
            default => null,
        };
        if (null === $context) {
            return [];
        }

        return array_map(fn (TemplateDeclaration $template): array => $this->protocol->completionItem(
            $template->name,
            CompletionItemKind::File,
            $template->uri,
            $this->protocol->textEdit($context->range, $template->name),
        ), $this->indexes->forProject($request->project)->matching($context->prefix));
    }

    private function twigContext(string $text, Position $position): ?TemplateCompletionContext
    {
        return $this->directives->insideDirective($text, $this->converter->toByteOffset($text, $position))
            ? TemplateCompletionContext::fromTwig($text, $position, $this->converter)
            : null;
    }
}
