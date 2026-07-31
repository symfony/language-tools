<?php

namespace Symfony\Lsp\Tests\Parser\Yaml;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;

final class YamlDocumentParserTest extends TestCase
{
    public function testPreservesMappingsAfterAnIncompleteFlowCollection(): void
    {
        $source = <<<'YAML'
            framework:
                messenger:
                    routing:
                        App\Message\Report: [async
            services:
                App\Handler\ReportHandler:
                    arguments:
                        - !service '@app.reporter'
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser()))->parse($source);

        self::assertContains(
            ['services', 'App\Handler\ReportHandler', 'arguments'],
            array_map(static fn (YamlMapping $mapping): array => $mapping->path(), $mappings),
        );
    }

    public function testKeepsEnvironmentScopeOutsideTheConfigurationPath(): void
    {
        $source = <<<'YAML'
            when@test:
                framework:
                    router:
                        utf8: true
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser()))->parse($source);

        self::assertSame(
            [
                [['framework'], 'when@test'],
                [['framework', 'router'], 'when@test'],
                [['framework', 'router', 'utf8'], 'when@test'],
            ],
            array_map(static fn (YamlMapping $mapping): array => [$mapping->path(), $mapping->scope()], $mappings),
        );
    }
}
