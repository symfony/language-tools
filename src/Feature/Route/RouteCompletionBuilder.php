<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteCompletionBuilder
{
    public function __construct(private readonly LspProtocolMapper $protocol)
    {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function complete(RouteIndex $routeIndex, string $prefix): array
    {
        return array_map(
            fn (Route $route): array => $this->protocol->completionItem($route->name, CompletionItemKind::Value, $route->path ?? 'Symfony route'),
            $routeIndex->matching($prefix),
        );
    }
}
