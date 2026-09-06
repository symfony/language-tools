<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;

final class RoutePhpReceiverResolver
{
    private const ROUTER_TYPES = [
        'Symfony\\Component\\Routing\\RouterInterface',
        'Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface',
    ];

    public function resolve(PhpDocument $document, PhpMethodCall $call): ?RoutePhpReceiver
    {
        if (!\in_array($call->method, RoutePhpMethods::ALL, true)) {
            return null;
        }
        if (PhpMethodReceiverKind::This === $call->receiverContext->kind) {
            return null === $call->className ? null : new RoutePhpReceiver($call->className);
        }
        if (null === $call->receiverContext->name) {
            return null;
        }

        return $document->receiverHasType($call, ...self::ROUTER_TYPES) ? new RoutePhpReceiver(null) : null;
    }
}
