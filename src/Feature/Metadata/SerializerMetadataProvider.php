<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SerializerMetadataProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
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
        if (null === $context || MetadataCompletionKind::SerializerGroup !== $context->kind()) {
            return null;
        }
        $items = [];
        foreach ($this->sourceIndexes->forProject($request->project)->names(MetadataSymbolKind::SerializerGroup) as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'detail' => 'Serializer group',
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $name),
            ];
        }

        return $items;
    }
}
