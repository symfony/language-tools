<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\Metadata\MetadataExtractor;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectTestKit;
use Symfony\Lsp\Tests\Support\ProviderRequests;

abstract class MetadataTestCase extends TestCase
{
    protected function extractor(): MetadataExtractor
    {
        return (new ProjectTestKit())->get(MetadataExtractor::class);
    }

    /** @return list<string> */
    protected function completionLabels(CompletionProviderInterface $provider, ProviderRequests $requests, string $uri, string $text, int $offset): array
    {
        /** @var list<string> $labels */
        $labels = array_column($provider->complete($requests->positioned(LspRequests::offset($uri, $text, $offset))), 'label');

        return $labels;
    }

    /**
     * @param list<HoverProviderInterface> $providers
     *
     * @return array<array-key, mixed>|null
     */
    protected function hover(array $providers, ProviderRequests $requests, string $uri, string $text, int $offset): ?array
    {
        foreach ($providers as $provider) {
            if (null !== $hover = $provider->hover($requests->positioned(LspRequests::offset($uri, $text, $offset)))) {
                return $hover;
            }
        }

        return null;
    }

    /**
     * @param list<DiagnosticProviderInterface> $providers
     *
     * @return list<array<array-key, mixed>>
     */
    protected function diagnostics(array $providers, ProviderRequests $requests, string $uri): array
    {
        $diagnostics = [];
        foreach ($providers as $provider) {
            $provided = $provider->diagnostics($requests->document($uri));
            if (null !== $provided) {
                array_push($diagnostics, ...$provided);
            }
        }

        return $diagnostics;
    }
}
