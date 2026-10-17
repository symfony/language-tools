<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\ParameterExpressionScanner;
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
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Tests\Support\ProjectPaths;

final class DependencyInjectionDocumentExtractorTest extends TestCase
{
    private const SERVICES = <<<'YAML'
        services:
            web:
                image: '@registry/web'
                environment:
                    - SYMFONY_IDE=phpstorm://open?file=%f&line=%l&/app/>${PWD}/
        YAML;

    #[DataProvider('yamlOutsideConfigurationDirectoriesProvider')]
    public function testIgnoresYamlOutsideConfigurationDirectories(string $root, string $path): void
    {
        $extractor = $this->extractor();
        $document = new SourceDocument('file://'.$root.'/'.$path, 'yaml', self::SERVICES);
        $project = new Project($root, 'file://'.$root);

        self::assertNull($extractor->extractForIndexing($project, $document));
        self::assertNull($extractor->extractForInteractive($project, $document));
    }

    /** @return iterable<string, array{string, string}> */
    public static function yamlOutsideConfigurationDirectoriesProvider(): iterable
    {
        yield 'docker compose' => ['/workspace', 'compose.yaml'];
        yield 'nested docker compose' => ['/workspace', 'docker/docker-compose.override.yml'];
        yield 'translation catalog' => ['/workspace', 'translations/messages.en.yaml'];
        yield 'test fixture' => ['/workspace', 'tests/Fixtures/app/config.yml'];
        yield 'project inside a config directory' => ['/home/me/config/app', 'compose.yaml'];
    }

    #[DataProvider('yamlInsideConfigurationDirectoriesProvider')]
    public function testExtractsYamlInsideConfigurationDirectories(string $path, string $configPath = 'config'): void
    {
        $facts = $this->extractor()->extractForIndexing(
            new Project('/workspace', 'file:///workspace', configPath: $configPath),
            new SourceDocument('file:///workspace/'.$path, 'yaml', "services:\n    app.foo:\n        arguments: ['@app.bar']\n"),
        );

        self::assertInstanceOf(DependencyInjectionSourceFacts::class, $facts);
        self::assertSame(['app.foo'], array_map(static fn ($service): string => $service->id, $facts->services));
        self::assertSame(['app.bar'], array_map(static fn ($reference): string => $reference->name, $facts->references));
    }

    /** @return iterable<string, array{0: string, 1?: string}> */
    public static function yamlInsideConfigurationDirectoriesProvider(): iterable
    {
        yield 'application' => ['config/services.yaml'];
        yield 'custom application directory' => ['etc/services.yaml', 'etc'];
        yield 'bundle' => ['src/AcmeBundle/config/services.yaml'];
        yield 'legacy bundle' => ['src/AcmeBundle/Resources/config/services.yaml'];
    }

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

        $project = new Project('/workspace', 'file:///workspace');
        $facts = $extractor->extractForIndexing($project, new SourceDocument($uri, 'xml', $text));

        self::assertInstanceOf(DependencyInjectionSourceFacts::class, $facts);
        self::assertSame(['app.mailer'], array_map(static fn ($service): string => $service->id, $facts->services));
        self::assertNull($extractor->extractForInteractive($project, new SourceDocument($uri, 'xml', $text)));
    }

    public function testPhpExtractionCombinesAutowireReferencesAndClassDeclarations(): void
    {
        $facts = $this->extractor()->extractForIndexing(
            new Project('/workspace', 'file:///workspace'),
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
            ProjectPaths::resolver(),
            new YamlDependencyInjectionExtractor(
                new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
                new YamlDependencyInjectionDeclarationExtractor($converter),
                new YamlDependencyInjectionReferenceExtractor($converter, new ParameterExpressionScanner()),
            ),
            new XmlDependencyInjectionExtractor($converter, new ParameterExpressionScanner()),
            new PhpAutowireReferenceExtractor($converter, $parser, new ParameterExpressionScanner()),
            new PhpClassDeclarationExtractor($converter, $parser),
        );
    }
}
