<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionProjectLookup;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionRenameHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\Service;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Feature\RenameEditBuilder;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\ProviderRequests;

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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
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
            new LspProtocolMapper(),
            new DependencyInjectionSymbolResolver($converter, $this->extractor($converter, $yamlExtractor)),
            $sourceIndexes,
            new DependencyInjectionProjectLookup(
                new ServiceIndexRegistry(),
                new ParameterIndexRegistry(),
                $sourceIndexes,
            ),
            ProjectPaths::resolver(),
            new RenameEditBuilder(new LspProtocolMapper()),
        );
        $position = $converter->toPosition($yaml, strpos($yaml, 'app.mailer') + 1);
        $requests = new ProviderRequests($documents, $projects);
        $params = LspRequests::position($yamlUri, $position);

        self::assertSame('app.mailer', $handler->prepare($requests->positioned($params))['placeholder'] ?? null);
        $result = $handler->rename($requests->rename($params, 'app.primary_mailer'));
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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $handler = new DependencyInjectionRenameHandler(
            new LspProtocolMapper(),
            new DependencyInjectionSymbolResolver($converter, $this->extractor($converter, $yamlExtractor)),
            $sourceIndexes,
            new DependencyInjectionProjectLookup(
                new ServiceIndexRegistry(),
                new ParameterIndexRegistry(),
                $sourceIndexes,
            ),
            ProjectPaths::resolver(),
            new RenameEditBuilder(new LspProtocolMapper()),
        );
        $position = $converter->toPosition($text, strpos($text, 'app.storage_dir') + 1);

        $result = $handler->rename((new ProviderRequests($documents, $projects))->rename(LspRequests::position($uri, $position), 'app.data_dir'));
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);
        self::assertIsArray($result['documentChanges'][0]);
        self::assertIsArray($result['documentChanges'][0]['edits']);

        self::assertSame(
            ['app.data_dir', 'app.data_dir'],
            array_column($result['documentChanges'][0]['edits'], 'newText'),
        );
    }

    public function testRejectsNamesCollidingWithRuntimeOrSourceSymbols(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                current.parameter: value
                source.parameter: value
            services:
                current.service: ~
                source.service: ~
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(
            true,
            new Service('runtime.service', null, null, false, false, null, [], null, []),
        );
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(true, new Parameter('runtime.parameter', null));
        $handler = new DependencyInjectionRenameHandler(
            new LspProtocolMapper(),
            new DependencyInjectionSymbolResolver($converter, $this->extractor($converter, $yamlExtractor)),
            $sourceIndexes,
            new DependencyInjectionProjectLookup($serviceIndexes, $parameterIndexes, $sourceIndexes),
            ProjectPaths::resolver(),
            new RenameEditBuilder(new LspProtocolMapper()),
        );
        $servicePosition = $converter->toPosition($text, strpos($text, 'current.service') + 1);
        $parameterPosition = $converter->toPosition($text, strpos($text, 'current.parameter') + 1);
        $requests = new ProviderRequests($documents, $projects);
        $serviceParams = LspRequests::position($uri, $servicePosition);
        $parameterParams = LspRequests::position($uri, $parameterPosition);

        self::assertNull($handler->rename($requests->rename($serviceParams, 'runtime.service')));
        self::assertNull($handler->rename($requests->rename($serviceParams, 'source.service')));
        self::assertNull($handler->rename($requests->rename($parameterParams, 'runtime.parameter')));
        self::assertNull($handler->rename($requests->rename($parameterParams, 'source.parameter')));
    }

    private function extractor(PositionConverter $converter, YamlDependencyInjectionExtractor $yamlExtractor): DependencyInjectionDocumentExtractor
    {
        $parser = new TolerantPhpParser(new Parser());

        return new DependencyInjectionDocumentExtractor(
            $yamlExtractor,
            new XmlDependencyInjectionExtractor($converter),
            new PhpAutowireReferenceExtractor($converter, $parser),
            new PhpClassDeclarationExtractor($converter, $parser),
        );
    }
}
