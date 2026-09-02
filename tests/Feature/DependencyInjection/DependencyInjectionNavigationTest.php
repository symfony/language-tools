<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDefinitionHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionProjectLookup;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReferencesHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
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

final class DependencyInjectionNavigationTest extends TestCase
{
    public function testNavigatesToServiceDeclarationsAndClasses(): void
    {
        [$definition, , $params] = $this->handlers();

        $locations = $definition->definition($params);

        self::assertSame([
            'file:///workspace/config/services.yaml',
            'file:///workspace/config/decorator.yaml',
            'file:///workspace/src/RuntimeMailer.php',
            'file:///workspace/src/Mailer.php',
            'file:///workspace/src/RuntimeAlias.php',
        ], array_column($locations ?? [], 'uri'));
    }

    public function testFindsYamlAndAutowireReferencesWithDeclarations(): void
    {
        [, $references, $params] = $this->handlers();
        $params['context'] = ['includeDeclaration' => true];

        $locations = $references->references($params);

        self::assertSame([
            'file:///workspace/config/services.yaml',
            'file:///workspace/src/Consumer.php',
            'file:///workspace/config/services.yaml',
        ], array_column($locations ?? [], 'uri'));
    }

    /**
     * @return array{DependencyInjectionDefinitionHandler, DependencyInjectionReferencesHandler, array<string, mixed>}
     */
    private function handlers(): array
    {
        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = <<<'YAML'
            services:
                app.mailer:
                    class: App\Mailer
                mailer: '@app.mailer'
            YAML;
        $classUri = 'file:///workspace/src/Mailer.php';
        $class = '<?php namespace App; final class Mailer {}';
        $consumerUri = 'file:///workspace/src/Consumer.php';
        $consumer = "<?php use Symfony\\Component\\DependencyInjection\\Attribute\\Autowire; #[Autowire(service: 'app.mailer')] final class Consumer {}";
        $documents = new DocumentStore();
        $documents->open(new Document($consumerUri, 'php', 1, $consumer));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $phpParser = new TolerantPhpParser(new Parser());
        $autowireExtractor = new PhpAutowireReferenceExtractor($converter, $phpParser);
        $classExtractor = new PhpClassDeclarationExtractor($converter, $phpParser);
        $extractor = new DependencyInjectionDocumentExtractor(
            $yamlExtractor,
            new XmlDependencyInjectionExtractor($converter),
            $autowireExtractor,
            $classExtractor,
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $range = new Range(new Position(0, 0), new Position(0, 1));
        $sourceIndexes->forProject($project)->replace(
            $yamlExtractor->extract($yamlUri, $yaml),
            new DependencyInjectionSourceFacts(
                $classUri,
                classes: $classExtractor->extract($classUri, $class),
            ),
            new DependencyInjectionSourceFacts(
                $consumerUri,
                references: $autowireExtractor->extract($consumerUri, $consumer),
                classes: $classExtractor->extract($consumerUri, $consumer),
            ),
            new DependencyInjectionSourceFacts(
                'file:///workspace/config/decorator.yaml',
                services: [new ServiceDeclaration(
                    'app.decorator',
                    'file:///workspace/config/decorator.yaml',
                    $range,
                    decorates: 'app.mailer',
                )],
            ),
            new DependencyInjectionSourceFacts(
                'file:///workspace/src/runtime-classes',
                classes: [
                    new PhpClassDeclaration(
                        'App\\RuntimeMailer',
                        'file:///workspace/src/RuntimeMailer.php',
                        $range,
                    ),
                    new PhpClassDeclaration(
                        'App\\RuntimeAlias',
                        'file:///workspace/src/RuntimeAlias.php',
                        $range,
                    ),
                ],
            ),
        );
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(
            true,
            new Service('app.mailer', 'App\\RuntimeMailer', 'runtime.alias', false, false, null, [], null, []),
            new Service('runtime.alias', 'App\\RuntimeAlias', null, false, false, null, [], null, []),
        );
        $resolver = new DependencyInjectionSymbolResolver($converter, $extractor);
        $contextResolver = new DocumentContextResolver($documents, $projects);
        $position = $converter->toPosition($consumer, strpos($consumer, 'app.mailer') + 1);
        $params = [
            'textDocument' => ['uri' => $consumerUri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];

        return [
            new DependencyInjectionDefinitionHandler(
                $contextResolver,
                new LspProtocolMapper(),
                $resolver,
                new DependencyInjectionProjectLookup($serviceIndexes, new ParameterIndexRegistry(), $sourceIndexes),
            ),
            new DependencyInjectionReferencesHandler($contextResolver, new LspProtocolMapper(), $resolver, $sourceIndexes),
            $params,
        ];
    }
}
