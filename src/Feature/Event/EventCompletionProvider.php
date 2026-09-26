<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class EventCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly EventIndexRegistry $indexes,
        private readonly EventExtractor $extractor,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $offset = $request->offset;
        $prefix = $this->extractor->completionPrefix($request->document->languageId, $request->document->text, $offset);
        if (null === $prefix) {
            return [];
        }
        $items = [];
        foreach ($this->indexes->forProject($request->project)->events() as $event) {
            if (str_starts_with($event->name, $prefix)) {
                $items[] = $this->completion($event->name, $request->document->text, $offset - \strlen($prefix), $request->position);
            }
        }

        return $items;
    }

    /** @return array<array-key, mixed> */
    private function completion(string $name, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return $this->protocol->completionItem($name, CompletionItemKind::Value, textEdit: $this->protocol->textEdit(new Range($position, $end), $name));
    }
}
