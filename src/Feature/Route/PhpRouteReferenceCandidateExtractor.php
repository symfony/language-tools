<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;

final class PhpRouteReferenceCandidateExtractor
{
    public function __construct(
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParser $phpComments,
        private readonly RouteParameterKeyExtractor $parameterKeys,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(string $text, PhpDocument $document): array
    {
        $masked = $this->phpComments->mask($text);
        $references = [];
        foreach ($this->matcher->methodCalls($masked, RoutePhpMethods::ALL) as $call) {
            $receiverOffset = $call->nameOffset - 2;
            $receiver = $this->resolveReceiver(
                substr($masked, 0, $receiverOffset),
                $receiverOffset,
                $document,
            );
            if (null === $receiver) {
                continue;
            }

            $references[] = new RouteReference(
                $call->value,
                $call->range,
                $this->parameterKeys->extract(substr($masked, $call->end())),
                $receiver->controllerClass,
            );
        }

        return $references;
    }

    public function resolveReceiver(string $source, int $offset, PhpDocument $document): ?RoutePhpReceiver
    {
        return RoutePhpReceiver::resolve($source, $offset, $document->typeDeclarations);
    }
}
