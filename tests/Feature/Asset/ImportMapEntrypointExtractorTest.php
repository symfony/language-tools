<?php

namespace Symfony\Lsp\Tests\Feature\Asset;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Asset\AssetSourceSymbol;
use Symfony\Lsp\Feature\Asset\ImportMapEntrypointExtractor;
use Symfony\Lsp\Parser\Php\PhpCommentParser;

final class ImportMapEntrypointExtractorTest extends TestCase
{
    public function testIgnoresEntriesInComments(): void
    {
        $symbols = $this->extract(<<<'PHP'
            <?php
            return [
                // 'commented' => ['path' => './assets/commented.js', 'entrypoint' => true],
                /* 'blocked' => ['path' => './assets/blocked.js', 'entrypoint' => true], */
                'live' => ['path' => './assets/live.js', 'entrypoint' => true],
            ];
            PHP);

        self::assertSame(['live'], array_map(static fn ($symbol): string => $symbol->name, $symbols));
    }

    public function testExtractsCompletedEntriesFromMalformedSource(): void
    {
        $symbols = $this->extract(<<<'PHP'
            <?php
            return [
                'app' => [
                    'path' => './assets/[app].js',
                    'entrypoint' => true,
                ],
                'package' => [
                    'path' => './assets/package.js',
                    'entrypoint' => false,
                ],
                'broken' => [
                    'entrypoint' => true,
            PHP);

        self::assertSame(['app'], array_map(static fn ($symbol): string => $symbol->name, $symbols));
    }

    /** @return list<AssetSourceSymbol> */
    private function extract(string $text): array
    {
        return (new ImportMapEntrypointExtractor(new PositionConverter(), new PhpCommentParser()))
            ->extract('file:///workspace/importmap.php', $text);
    }
}
