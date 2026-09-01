<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

final class DependencyInjectionDocumentExtractorTest extends TestCase
{
    public function testXmlDefinitionsAreIndexedWithoutEnablingInteractiveFeatures(): void
    {
        $extractor = $this->extractor();
        $uri = 'file:///workspace/config/services.xml';
        $text = <<<'XML'
            <container xmlns="http://symfony.com/schema/dic/services">
                <services>
                    <service id="app.mailer"/>
                </services>
            </container>
            XML;

        $facts = $extractor->extractForIndexing(new SourceDocument($uri, 'xml', $text));

        self::assertInstanceOf(DependencyInjectionSourceFacts::class, $facts);
        self::assertSame(['app.mailer'], array_map(static fn ($service): string => $service->id, $facts->services));
        self::assertNull($extractor->extractForInteractive(new SourceDocument($uri, 'xml', $text)));
    }

    public function testPhpExtractionCombinesAutowireReferencesAndClassDeclarations(): void
    {
        $facts = $this->extractor()->extractForIndexing(
            new SourceDocument('file:///workspace/src/Consumer.php',
                'php',
                <<<'PHP'
                <?php

                namespace App;

                use Symfony\Component\DependencyInjection\Attribute\Autowire;

                final class Consumer
                {
                    public function __construct(#[Autowire(service: 'app.mailer')] object $mailer)
                    {
                    }
                }
                PHP),
        );

        self::assertInstanceOf(DependencyInjectionSourceFacts::class, $facts);
        self::assertSame(['app.mailer'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame(['App\Consumer'], array_map(static fn ($class): string => $class->className, $facts->classes));
    }

    private function extractor(): DependencyInjectionDocumentExtractor
    {
        $converter = new PositionConverter();
        $parser = new TolerantPhpParser(new Parser());

        return new DependencyInjectionDocumentExtractor(
            new YamlDependencyInjectionExtractor(
                new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
                new YamlDependencyInjectionDeclarationExtractor($converter),
                new YamlDependencyInjectionReferenceExtractor($converter),
            ),
            new XmlDependencyInjectionExtractor($converter),
            new PhpAutowireReferenceExtractor($converter, $parser),
            new PhpClassDeclarationExtractor($converter, $parser),
        );
    }
}
