<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
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
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $document = $request->document;
        $context = match ($document->languageId) {
            'php' => $this->extractor->phpCompletionAt(
                $document->text,
                $this->converter->toByteOffset($document->text, $request->position),
                $this->classIndexes->forProject($request->project),
            ),
            'twig' => $this->twigContext($this->comments->mask('twig', $document->text), $request->position),
            default => null,
        };
        if (null === $context) {
            return null;
        }

        return array_map(fn (TemplateDeclaration $template): array => [
            'label' => $template->name,
            'kind' => 17,
            'detail' => $template->uri,
            'textEdit' => $this->protocol->textEdit($context->range, $template->name),
        ], $this->indexes->forProject($request->project)->matching($context->prefix));
    }

    private function twigContext(string $text, Position $position): ?TemplateCompletionContext
    {
        return $this->directives->insideDirective($text, $this->converter->toByteOffset($text, $position))
            ? TemplateCompletionContext::fromTwig($text, $position, $this->converter)
            : null;
    }
}
