<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class TwigCallableCallExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly TwigCallableArgumentAnalyzer $arguments,
        private readonly TwigCommentParser $comments,
    ) {
    }

    /** @return list<TwigCallableCallReference> */
    public function extract(SourceDocument $document): array
    {
        if ('twig' !== $document->languageId) {
            return [];
        }
        $source = $this->comments->mask($document->text);
        $calls = [];
        foreach ($this->arguments->completeCalls($source) as $call) {
            if (!$this->references->insideDirective($source, $call->calleeOffset)) {
                continue;
            }
            $arguments = [];
            foreach ($call->arguments as $argument) {
                if (null === $argument->name || null === $argument->nameOffset) {
                    continue;
                }
                $arguments[] = new TwigCallableArgumentReference(
                    $argument->name,
                    $this->converter->toRange($document->text, $argument->nameOffset, \strlen($argument->name)),
                );
            }
            $calls[] = new TwigCallableCallReference($call->kind, $call->callee, $arguments);
        }

        return $calls;
    }
}
