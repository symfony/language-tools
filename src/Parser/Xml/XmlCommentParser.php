<?php

namespace Symfony\Lsp\Parser\Xml;

use Symfony\Lsp\Parser\AbstractCommentParser;
use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\SourceComment;

final class XmlCommentParser extends AbstractCommentParser
{
    public function __construct(
        private readonly XmlParserInterface $parser = new TolerantXmlParser(),
    ) {
    }

    protected function parseSource(string $source): CommentParseResult
    {
        $masked = $source;
        $comments = [];
        foreach ($this->parser->parse($source)->events as $event) {
            if (!$event instanceof XmlOpaque || XmlOpaqueKind::Comment !== $event->kind) {
                continue;
            }
            $comments[] = new SourceComment(
                $event->startOffset,
                $event->endOffset,
                $event->contentStartOffset,
                $event->contentEndOffset,
                substr($source, $event->contentStartOffset, $event->contentEndOffset - $event->contentStartOffset),
            );
            $this->maskRange($masked, $source, $event->startOffset, $event->endOffset);
        }

        return new CommentParseResult($masked, $comments);
    }
}
