<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\Metadata\FormMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\MetadataExtractor;
use Symfony\Lsp\Feature\Metadata\SerializerMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\ValidationMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\YamlMetadataExtractor;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

abstract class MetadataTestCase extends TestCase
{
    protected function createExtractor(PositionConverter $converter): MetadataExtractor
    {
        $parser = new TolerantPhpParser(new Parser());
        $comments = new PhpCommentParser();

        return new MetadataExtractor(
            $converter,
            $parser,
            $comments,
            new FormMetadataExtractor($converter, new BalancedDelimiterMatcher(), new PhpLiteralArrayKeyParser()),
            new ValidationMetadataExtractor($converter),
            new SerializerMetadataExtractor($converter),
            new YamlMetadataExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))),
        );
    }

    /** @return list<string> */
    protected function completionLabels(CompletionProviderInterface $provider, PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        /** @var list<string> $labels */
        $labels = array_column($provider->complete($this->params($converter, $uri, $text, $offset)) ?? [], 'label');

        return $labels;
    }

    /**
     * @param list<HoverProviderInterface> $providers
     *
     * @return array<array-key, mixed>|null
     */
    protected function hover(array $providers, PositionConverter $converter, string $uri, string $text, int $offset): ?array
    {
        foreach ($providers as $provider) {
            if (null !== $hover = $provider->hover($this->params($converter, $uri, $text, $offset))) {
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
    protected function diagnostics(array $providers, string $uri): array
    {
        $diagnostics = [];
        foreach ($providers as $provider) {
            $provided = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
            if (null !== $provided) {
                array_push($diagnostics, ...$provided);
            }
        }

        return $diagnostics;
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    protected function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }
}
