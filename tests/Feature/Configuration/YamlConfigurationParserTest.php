<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

final class YamlConfigurationParserTest extends TestCase
{
    public function testNormalizesConfigurationPathsWithoutChangingYamlSyntaxFacts(): void
    {
        $documentParser = new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
        $configurationParser = new YamlConfigurationParser(new PositionConverter(), $documentParser);
        $source = "framework:\n    property-info:\n        with-hyphen: true\n";

        self::assertSame(['framework', 'property-info', 'with-hyphen'], $documentParser->parse($source)[2]->path);
        self::assertSame(['framework', 'property_info', 'with_hyphen'], $configurationParser->parse($source)[2]->path());
    }
}
