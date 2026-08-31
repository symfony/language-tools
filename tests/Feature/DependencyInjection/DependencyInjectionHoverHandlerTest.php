<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionHoverHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\Service;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionHoverHandlerTest extends TestCase
{
    public function testDisplaysSafeServiceAndParameterMetadata(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.api_key: 'CANARY_SECRET_VALUE'
            services:
                app.consumer:
                    arguments: ['@app.mailer', '%app.api_key%']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $phpParser = new TolerantPhpParser(new Parser());
        $extractor = new DependencyInjectionDocumentExtractor(
            $yamlExtractor,
            new XmlDependencyInjectionExtractor($converter),
            new PhpAutowireReferenceExtractor($converter, $phpParser),
            new PhpClassDeclarationExtractor($converter, $phpParser),
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            true,
            'Use app.new_mailer.',
            ['kernel.reset'],
            'mailer',
            ['App\\MailerInterface'],
            ['app.mailer', 'mailer.inner'],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.api_key', 'Use app.new_api_key.'),
        );
        $handler = new DependencyInjectionHoverHandler(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            new DependencyInjectionSymbolResolver($converter, $extractor),
            $serviceIndexes,
            $parameterIndexes,
            $sourceIndexes,
        );

        $serviceHover = $handler->hover($this->params($uri, $text, 'app.mailer', $converter));
        $parameterHover = $handler->hover($this->params($uri, $text, 'app.api_key%', $converter));
        self::assertIsArray($serviceHover);
        self::assertIsArray($serviceHover['contents']);
        self::assertIsArray($parameterHover);
        self::assertIsArray($parameterHover['contents']);

        self::assertSame(<<<'MARKDOWN'
            Service: `app.mailer`

            Class: `App\Mailer`

            Visibility: private

            Lazy: yes

            Deprecated: Use app.new_mailer.

            Decorates: `mailer`

            Tags: `kernel.reset`

            Autowiring types: `App\MailerInterface`

            Decoration stack: `app.mailer` → `mailer.inner`
            MARKDOWN, $serviceHover['contents']['value'] ?? null);
        self::assertSame(<<<'MARKDOWN'
            Parameter: `app.api_key`

            Deprecated: Use app.new_api_key.
            MARKDOWN, $parameterHover['contents']['value'] ?? null);
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            json_encode([$serviceHover, $parameterHover], \JSON_THROW_ON_ERROR),
        );
    }

    public function testUsesRuntimeServiceMetadataWithSourceFallbacks(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = "services:\n    app.shared: ~\n";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $phpParser = new TolerantPhpParser(new Parser());
        $extractor = new DependencyInjectionDocumentExtractor(
            $yamlExtractor,
            new XmlDependencyInjectionExtractor($converter),
            new PhpAutowireReferenceExtractor($converter, $phpParser),
            new PhpClassDeclarationExtractor($converter, $phpParser),
        );
        $parsedDeclaration = $yamlExtractor->extract($uri, $text)->services[0];
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(new DependencyInjectionSourceFacts($uri, [new ServiceDeclaration(
            'app.shared',
            $uri,
            $parsedDeclaration->range,
            'App\\SourceShared',
            'source.alias',
            'source.decorated',
            ['source.tag'],
        )]));
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.shared',
            'App\\RuntimeShared',
            null,
            false,
            true,
            'Use app.replacement.',
            [],
            'runtime.decorated',
            ['App\\SharedInterface'],
            ['app.shared', 'app.inner'],
        ));
        $handler = new DependencyInjectionHoverHandler(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            new DependencyInjectionSymbolResolver($converter, $extractor),
            $serviceIndexes,
            new ParameterIndexRegistry(),
            $sourceIndexes,
        );

        $hover = $handler->hover($this->params($uri, $text, 'app.shared', $converter));

        self::assertSame(<<<'MARKDOWN'
            Service: `app.shared`

            Alias of: `source.alias`

            Class: `App\RuntimeShared`

            Visibility: private

            Lazy: yes

            Deprecated: Use app.replacement.

            Decorates: `runtime.decorated`

            Autowiring types: `App\SharedInterface`

            Decoration stack: `app.shared` → `app.inner`
            MARKDOWN, $hover['contents']['value'] ?? null);
    }

    /** @return array<string, mixed> */
    private function params(string $uri, string $text, string $needle, PositionConverter $converter): array
    {
        $offset = strpos($text, $needle);
        self::assertIsInt($offset);
        $position = $converter->toPosition($text, $offset + 1);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];
    }
}
