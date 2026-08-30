<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusExtractor $extractor,
        private readonly StimulusResolver $stimulus,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return null;
        }
        $values = null === $context->kind()
            ? $this->stimulus->controllerNames($request->project)
            : $this->stimulus->members($request->project, $context->controller() ?? '', $context->kind());
        $items = [];
        foreach ($values as $value) {
            if (!str_starts_with($value, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $value,
                'kind' => null === $context->kind() ? 7 : 2,
                'detail' => null === $context->kind() ? 'Stimulus controller' : \sprintf('Stimulus %s', $context->kind()->value),
                'textEdit' => $this->protocol->textEdit($context->range(), $value),
            ];
        }

        return $items;
    }
}
