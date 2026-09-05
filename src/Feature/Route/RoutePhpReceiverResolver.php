<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;

final class RoutePhpReceiverResolver
{
    private const ROUTER_TYPES = [
        'Symfony\\Component\\Routing\\RouterInterface',
        'Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface',
    ];

    public function __construct(private readonly PhpCapturedReceiverResolver $receivers)
    {
    }

    public function resolve(string $source, PhpDocument $document, PhpMethodCall $call): ?RoutePhpReceiver
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

        return array_any($this->receivers->variables($source, $document, $call), self::isRouter(...))
            ? new RoutePhpReceiver(null)
            : null;
    }

    private static function isRouter(PhpTypedVariable $variable): bool
    {
        return [] !== array_intersect(self::ROUTER_TYPES, $variable->types);
    }
}
