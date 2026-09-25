<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Protocol\LspProtocolMapper;

final class HoverProviderRegistry
{
    /** @param iterable<HoverProviderInterface> $providers */
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function hover(array $params): ?array
    {
        $values = [];
        foreach ($this->providers as $provider) {
            $value = $this->markdown($provider->hover($params));
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return [] === $values ? null : $this->protocol->markdownHover(implode("\n\n---\n\n", $values));
    }

    /** @param array<array-key, mixed>|null $hover */
    private function markdown(?array $hover): string
    {
        $contents = $hover['contents'] ?? null;
        $value = \is_array($contents) ? $contents['value'] ?? null : $contents;

        return \is_string($value) ? $value : '';
    }
}
