<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;

final class DoctrineCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
        private readonly DoctrineFieldCompletionBuilder $completionBuilder,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);

        return match ($context->kind) {
            DoctrineCompletionKind::EntityTypeField => $this->completionBuilder->build($context, $index),
            DoctrineCompletionKind::RepositoryCriteria => $this->completionBuilder->build($context, $index),
        };
    }
}
