<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionRenameHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DependencyInjectionRenameHandlerTest extends TestCase
{
    public function testRenamesApplicationOwnedDeclarationsAndStaticReferences(): void
    {
        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = <<<'YAML'
            services:
                app.mailer: ~
                mailer: '@app.mailer'
            YAML;
        $phpUri = 'file:///workspace/src/Consumer.php';
        $php = "<?php use Symfony\\Component\\DependencyInjection\\Attribute\\Autowire; #[Autowire(service: 'app.mailer')] final class Consumer {}";
        $documents = new DocumentStore();
        $documents->open(new Document($yamlUri, 'yaml', 1, $yaml));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor($converter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser()));
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $yamlExtractor->extract($yamlUri, $yaml),
            new DependencyInjectionSourceFacts(
                $phpUri,
                references: $autowireExtractor->extract($phpUri, $php),
            ),
        );
        $handler = new DependencyInjectionRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new DependencyInjectionSymbolResolver($converter, $yamlExtractor, $autowireExtractor),
            $sourceIndexes,
            new ServiceIndexRegistry(),
            new ParameterIndexRegistry(),
        );
        $position = $converter->toPosition($yaml, strpos($yaml, 'app.mailer') + 1);
        $params = [
            'textDocument' => ['uri' => $yamlUri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];

        self::assertSame('app.mailer', $handler->prepare($params)['placeholder'] ?? null);
        $result = $handler->rename([...$params, 'newName' => 'app.primary_mailer']);
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);

        $uris = [];
        $newTexts = [];
        $editCount = 0;
        foreach ($result['documentChanges'] as $change) {
            self::assertIsArray($change);
            self::assertIsArray($change['textDocument']);
            self::assertIsString($change['textDocument']['uri']);
            self::assertIsArray($change['edits']);
            $uris[] = $change['textDocument']['uri'];
            $editCount += \count($change['edits']);
            foreach ($change['edits'] as $edit) {
                self::assertIsArray($edit);
                self::assertIsString($edit['newText']);
                $newTexts[] = $edit['newText'];
            }
        }

        self::assertSame([$yamlUri, $phpUri], $uris);
        self::assertSame(['app.primary_mailer'], array_values(array_unique($newTexts)));
        self::assertSame(3, $editCount);
    }

    public function testRenamesApplicationOwnedParametersWithoutChangingDelimiters(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.storage_dir: /storage
            services:
                app.consumer:
                    arguments: ['%app.storage_dir%']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor($converter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser()));
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $handler = new DependencyInjectionRenameHandler(
            new DocumentContextResolver($documents, $projects),
            new DependencyInjectionSymbolResolver($converter, $yamlExtractor, $autowireExtractor),
            $sourceIndexes,
            new ServiceIndexRegistry(),
            new ParameterIndexRegistry(),
        );
        $position = $converter->toPosition($text, strpos($text, 'app.storage_dir') + 1);

        $result = $handler->rename([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
            'newName' => 'app.data_dir',
        ]);
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);
        self::assertIsArray($result['documentChanges'][0]);
        self::assertIsArray($result['documentChanges'][0]['edits']);

        self::assertSame(
            ['app.data_dir', 'app.data_dir'],
            array_column($result['documentChanges'][0]['edits'], 'newText'),
        );
    }
}
