<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteReferenceCandidateExtractor;
use Symfony\Lsp\Feature\Route\RouteControllerClassifier;
use Symfony\Lsp\Feature\Route\RoutePhpReceiverResolver;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class RouteReferenceExtractorFactory
{
    public static function create(PositionConverter $converter, ?PhpParserInterface $parser = null): RouteReferenceExtractor
    {
        $receivers = new RoutePhpReceiverResolver();

        return new RouteReferenceExtractor(
            $converter,
            $parser ?? new TolerantPhpParser(new Parser()),
            new PhpRouteReferenceCandidateExtractor($converter, $receivers),
            $receivers,
            new RouteControllerClassifier(),
        );
    }
}
