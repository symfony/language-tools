<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\PositionedRequest;

final class DoctrineCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
        private readonly DoctrineFieldCompletionBuilder $completionBuilder,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $offset = $request->offset;
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);

        return match ($context->kind) {
            DoctrineCompletionKind::EntityTypeField => $this->completionBuilder->build($context, $index),
            DoctrineCompletionKind::RepositoryCriteria => $this->completionBuilder->build($context, $index),
        };
    }
}
