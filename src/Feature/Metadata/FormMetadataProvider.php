<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class FormMetadataProvider implements CompletionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $context = $this->extractor->completionContext($request->document->languageId(), $request->document->text(), $offset);
        if (null === $context || MetadataCompletionKind::FormOption !== $context->kind()) {
            return null;
        }
        $type = null === $context->owner() ? null : $this->indexes->forProject($request->project)->formType($context->owner());
        if (null === $type) {
            return [];
        }
        $items = [];
        foreach ($type->options() as $option) {
            if (!str_starts_with($option, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $option,
                'detail' => \in_array($option, $type->requiredOptions(), true) ? 'Required form option' : 'Form option',
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $option),
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->formOptions($request->document->text()) as $option) {
            if (!$this->contains($request->document, $option['range'], $offset)) {
                continue;
            }
            $type = $this->indexes->forProject($request->project)->formType($option['class']);
            if (null === $type || !\in_array($option['option'], $type->options(), true)) {
                return null;
            }
            $required = \in_array($option['option'], $type->requiredOptions(), true);

            return $this->protocol->markdownHover(\sprintf("Form option: `%s`\n\nType: `%s`\n\nRequired: %s", $option['option'], $type->className(), $required ? 'yes' : 'no'));
        }

        return null;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->formOptions($request->document->text()) as $option) {
            $type = $index->formType($option['class']);
            if (null !== $type && !\in_array($option['option'], $type->options(), true)) {
                $diagnostics[] = $this->diagnostic($option['range'], 'form.unknown_option', \sprintf('Unknown option "%s" for form type "%s".', $option['option'], $type->className()));
            }
        }

        return $diagnostics;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, 1, $code, $message);
    }
}
