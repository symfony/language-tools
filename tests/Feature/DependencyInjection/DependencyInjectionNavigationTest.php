<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDefinitionHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReferencesHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DependencyInjectionNavigationTest extends TestCase
{
    public function testNavigatesToServiceDeclarationsAndClasses(): void
    {
        [$definition, , $params] = $this->handlers();

        $locations = $definition->definition($params);

        self::assertSame([
            'file:///workspace/config/services.yaml',
            'file:///workspace/src/Mailer.php',
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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor($converter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser()));
        $classExtractor = new PhpClassDeclarationExtractor($converter);
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
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
        );
        $resolver = new DependencyInjectionSymbolResolver($converter, $yamlExtractor, $autowireExtractor);
        $contextResolver = new DocumentContextResolver($documents, $projects);
        $position = $converter->toPosition($consumer, strpos($consumer, 'app.mailer') + 1);
        $params = [
            'textDocument' => ['uri' => $consumerUri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];

        return [
            new DependencyInjectionDefinitionHandler(
                $contextResolver,
                $resolver,
                $sourceIndexes,
                new ServiceIndexRegistry(),
            ),
            new DependencyInjectionReferencesHandler($contextResolver, $resolver, $sourceIndexes),
            $params,
        ];
    }
}
