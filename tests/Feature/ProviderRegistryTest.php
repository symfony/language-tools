<?php

namespace Symfony\Lsp\Tests\Feature;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineExtractor;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineRelationshipProvider;
use Symfony\Lsp\Feature\Doctrine\DoctrineRepositoryReceiverResolver;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\Metadata\FormMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\MetadataExtractor;
use Symfony\Lsp\Feature\Metadata\MetadataRelationshipProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\SerializerMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\ValidationMetadataExtractor;
use Symfony\Lsp\Feature\Metadata\YamlMetadataExtractor;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\RenameRequest;
use Symfony\Lsp\Tests\Support\LspRequests;

final class ProviderRegistryTest extends TestCase
{
    public function testCompletionProvidersAggregateInOrderAndDistinguishNoMatchFromEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['label' => 'second']]);
        $third = new StubProvider([['label' => 'third']]);

        self::assertSame(
            [['label' => 'second'], ['label' => 'third']],
            (new CompletionProviderRegistry([$first, $second, $third]))->complete([]),
        );
        self::assertSame(['complete'], $first->calls);
        self::assertSame(['complete'], $second->calls);
        self::assertSame(['complete'], $third->calls);
        self::assertNull((new CompletionProviderRegistry([new StubProvider(null)]))->complete([]));
        self::assertSame([], (new CompletionProviderRegistry([new StubProvider([])]))->complete([]));
    }

    public function testDefinitionProvidersAggregateInOrderAndDistinguishNoMatchFromEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['uri' => 'file:///second']]);
        $third = new StubProvider([['uri' => 'file:///third']]);

        self::assertSame(
            [['uri' => 'file:///second'], ['uri' => 'file:///third']],
            (new DefinitionProviderRegistry([$first, $second, $third]))->definition([]),
        );
        self::assertSame(['definition'], $first->calls);
        self::assertSame(['definition'], $second->calls);
        self::assertSame(['definition'], $third->calls);
        self::assertNull((new DefinitionProviderRegistry([new StubProvider(null)]))->definition([]));
        self::assertSame([], (new DefinitionProviderRegistry([new StubProvider([])]))->definition([]));
    }

    public function testReferenceProvidersAggregateInOrderAndDistinguishNoMatchFromEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['uri' => 'file:///second']]);
        $third = new StubProvider([['uri' => 'file:///third']]);

        self::assertSame(
            [['uri' => 'file:///second'], ['uri' => 'file:///third']],
            (new ReferencesProviderRegistry([$first, $second, $third]))->references([]),
        );
        self::assertSame(['references'], $first->calls);
        self::assertSame(['references'], $second->calls);
        self::assertSame(['references'], $third->calls);
        self::assertNull((new ReferencesProviderRegistry([new StubProvider(null)]))->references([]));
        self::assertSame([], (new ReferencesProviderRegistry([new StubProvider([])]))->references([]));
    }

    public function testDocumentLinkProvidersAggregateEveryLinkOfAnOpenProjectDocument(): void
    {
        $first = new StubProvider([]);
        $second = new StubProvider([['target' => 'file:///second']]);
        $third = new StubProvider([['target' => 'file:///third']]);
        $uri = 'file:///workspace/templates/page.html.twig';
        $requests = $this->requestFactory($uri, 'twig', '');

        self::assertSame(
            [['target' => 'file:///second'], ['target' => 'file:///third']],
            (new DocumentLinkProviderRegistry($requests, [$first, $second, $third]))->links(LspRequests::document($uri)),
        );
        self::assertSame(['links'], $first->calls);
        self::assertSame(['links'], $second->calls);
        self::assertSame(['links'], $third->calls);
        self::assertSame([], (new DocumentLinkProviderRegistry($requests, [$first]))->links(LspRequests::document('file:///elsewhere/page.html.twig')));
        self::assertSame(['links'], $first->calls);
    }

    public function testCodeActionProvidersAggregateAllMatchesAndAlwaysReturnAList(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['title' => 'second']]);
        $third = new StubProvider([['title' => 'third']]);

        self::assertSame(
            [['title' => 'second'], ['title' => 'third']],
            (new CodeActionProviderRegistry([$first, $second, $third]))->actions([]),
        );
        self::assertSame(['actions'], $first->calls);
        self::assertSame(['actions'], $second->calls);
        self::assertSame(['actions'], $third->calls);
        self::assertSame([], (new CodeActionProviderRegistry([new StubProvider(null)]))->actions([]));
    }

    public function testCodeLensProvidersAggregateEveryLensOfAnOpenProjectDocument(): void
    {
        $first = new StubProvider([]);
        $second = new StubProvider([['command' => ['title' => 'second']]]);
        $third = new StubProvider([['command' => ['title' => 'third']]]);
        $uri = 'file:///workspace/src/Kernel.php';
        $requests = $this->requestFactory($uri, 'php', '<?php');

        self::assertSame(
            [['command' => ['title' => 'second']], ['command' => ['title' => 'third']]],
            (new CodeLensProviderRegistry($requests, [$first, $second, $third]))->codeLenses(LspRequests::document($uri)),
        );
        self::assertSame(['codeLenses'], $first->calls);
        self::assertSame(['codeLenses'], $second->calls);
        self::assertSame(['codeLenses'], $third->calls);
        self::assertSame([], (new CodeLensProviderRegistry($requests, [$first]))->codeLenses(LspRequests::document('file:///elsewhere/Kernel.php')));
        self::assertSame(['codeLenses'], $first->calls);
    }

    public function testHoverProvidersMergeEveryMatchInOrder(): void
    {
        $protocol = new LspProtocolMapper();
        $first = new StubProvider(null);
        $second = new StubProvider([$protocol->markdownHover('second')]);
        $third = new StubProvider([$protocol->markdownHover('third')]);

        self::assertSame(
            $protocol->markdownHover("second\n\n---\n\nthird"),
            (new HoverProviderRegistry($protocol, [$first, $second, $third]))->hover([]),
        );
        self::assertSame(['hover'], $first->calls);
        self::assertSame(['hover'], $second->calls);
        self::assertSame(['hover'], $third->calls);
        self::assertNull((new HoverProviderRegistry($protocol, [new StubProvider(null)]))->hover([]));

        $afterEmpty = new StubProvider([$protocol->markdownHover('later')]);
        self::assertSame(
            $protocol->markdownHover('later'),
            (new HoverProviderRegistry($protocol, [new StubProvider([[]]), $afterEmpty]))->hover([]),
        );
        self::assertSame(['hover'], $afterEmpty->calls);
    }

    public function testHoverProvidersMergeTheDoctrineFieldAndThePhpPropertyOfTheSameProperty(): void
    {
        $uri = 'file:///workspace/src/Entity/Product.php';
        $text = <<<'PHP'
            <?php
            namespace App\Entity;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity]
            class Product
            {
                #[ORM\ManyToOne(targetEntity: Category::class)]
                private ?Category $category = null;
            }
            PHP;
        $converter = new PositionConverter();
        $project = new Project('/workspace', 'file:///workspace');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $source = new SourceDocument($uri, 'php', $text);
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $positionedSymbols = new PositionedSourceSymbolResolver($converter);
        $phpParser = new TolerantPhpParser(new Parser());
        $phpComments = new PhpCommentParser();
        $doctrineExtractor = new DoctrineExtractor($converter, $phpParser, $phpComments, new DoctrineRepositoryReceiverResolver(), new PhpLiteralArrayKeyParser());
        $doctrineIndexes = new DoctrineIndexRegistry();
        $doctrineIndexes->forProject($project)->replace($doctrineExtractor->extract($source));
        $metadataExtractor = new MetadataExtractor(
            $converter,
            $phpParser,
            $phpComments,
            new FormMetadataExtractor($converter, new PhpLiteralArrayKeyParser()),
            new ValidationMetadataExtractor($converter),
            new SerializerMetadataExtractor($converter),
            new YamlMetadataExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))),
        );
        $metadataIndexes = new MetadataSourceIndexRegistry();
        $metadataIndexes->forProject($project)->replace($metadataExtractor->extract($source));
        $registry = new HoverProviderRegistry($protocol, [
            new MetadataRelationshipProvider($resolver, $positionedSymbols, $protocol, $metadataIndexes, $metadataExtractor),
            new DoctrineRelationshipProvider($resolver, $positionedSymbols, $protocol, $doctrineIndexes, $doctrineExtractor),
        ]);

        $position = $converter->toPosition($text, (int) strpos($text, '$category = null') + 2);
        $hover = $registry->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]);

        self::assertSame(
            $protocol->markdownHover("PHP property: `App\Entity\Product::\$category`\n\n```php\nprivate ?Category \$category\n```\n\n---\n\nDoctrine association: `App\Entity\Product::\$category`\n\nType: `App\Entity\Category`\n\nTarget entity: `App\Entity\Category`"),
            $hover,
        );
    }

    public function testRenamePreparationReturnsTheFirstMatchIncludingAnEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['placeholder' => 'second']]);
        $third = new StubProvider([['placeholder' => 'third']]);
        $uri = 'file:///workspace/src/Kernel.php';
        $requests = $this->requestFactory($uri, 'php', '<?php');
        $params = LspRequests::position($uri, new Position(0, 1));

        self::assertSame(
            ['placeholder' => 'second'],
            (new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [$first, $second, $third]))->prepare($params),
        );
        self::assertSame(['prepare'], $first->calls);
        self::assertSame(['prepare'], $second->calls);
        self::assertSame([], $third->calls);
        self::assertNull((new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [new StubProvider(null)]))->prepare($params));

        $afterEmpty = new StubProvider([['placeholder' => 'later']]);
        self::assertSame([], (new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [new StubProvider([[]]), $afterEmpty]))->prepare($params));
        self::assertSame([], $afterEmpty->calls);

        $unasked = new StubProvider([['placeholder' => 'never']]);
        self::assertNull((new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [$unasked]))->prepare(LspRequests::document($uri)));
        self::assertSame([], $unasked->calls);
    }

    public function testRenameProvidersReturnTheFirstMatchIncludingAnEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['changes' => ['second']]]);
        $third = new StubProvider([['changes' => ['third']]]);
        $uri = 'file:///workspace/src/Kernel.php';
        $requests = $this->requestFactory($uri, 'php', '<?php');
        $params = [...LspRequests::position($uri, new Position(0, 1)), 'newName' => 'renamed'];

        self::assertSame(
            ['changes' => ['second']],
            (new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [$first, $second, $third]))->rename($params),
        );
        self::assertSame(['rename'], $first->calls);
        self::assertSame(['rename'], $second->calls);
        self::assertSame([], $third->calls);
        self::assertNull((new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [new StubProvider(null)]))->rename($params));

        $afterEmpty = new StubProvider([['changes' => ['later']]]);
        self::assertSame([], (new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [new StubProvider([[]]), $afterEmpty]))->rename($params));
        self::assertSame([], $afterEmpty->calls);
    }

    public function testRenameRefusesAnEmptyOrMissingNewNameWithoutAskingAnyProvider(): void
    {
        $uri = 'file:///workspace/src/Kernel.php';
        $requests = $this->requestFactory($uri, 'php', '<?php');
        $position = LspRequests::position($uri, new Position(0, 1));
        $provider = new StubProvider([['changes' => ['never']]]);
        $registry = new RenameProviderRegistry($requests, new SourceOverlayHealthRegistry(), [$provider]);

        self::assertNull($registry->rename($position));
        self::assertNull($registry->rename([...$position, 'newName' => '']));
        self::assertNull($registry->rename([...$position, 'newName' => 42]));
        self::assertSame([], $provider->calls);
    }

    public function testRenameRefusesWorkspaceEditsTargetingADegradedDocument(): void
    {
        $health = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $health->record($project, 'file:///workspace/src/Target.php', SourceParseHealth::Partial);
        $uri = 'file:///workspace/src/Kernel.php';
        $provider = new StubProvider([[
            'documentChanges' => [[
                'textDocument' => ['uri' => 'file:///workspace/src/Target.php', 'version' => null],
                'edits' => [],
            ]],
        ]]);

        try {
            (new RenameProviderRegistry($this->requestFactory($uri, 'php', '<?php'), $health, [$provider]))->rename([...LspRequests::position($uri, new Position(0, 1)), 'newName' => 'renamed']);
            self::fail('The rename should have been refused.');
        } catch (JsonRpcException $error) {
            self::assertSame('Rename is unavailable while an affected open document cannot be analyzed completely.', $error->getMessage());
            self::assertSame(['rename'], $provider->calls);
        }
    }

    private function requestFactory(string $uri, string $languageId, string $text): LspRequestFactory
    {
        $projects = new ProjectRegistry();
        $projects->replace([new Project('/workspace', 'file:///workspace')]);
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));

        return new LspRequestFactory($documents, $projects, new PositionConverter());
    }
}

final class StubProvider implements CodeActionProviderInterface, CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface, RenameProviderInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<array-key, mixed>>|null */
    private readonly ?array $result;

    /** @param list<array<array-key, mixed>>|null $result */
    public function __construct(?array $result)
    {
        $this->result = $result;
    }

    public function actions(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function codeLenses(DocumentRequest $request): array
    {
        return $this->result(__FUNCTION__) ?? [];
    }

    public function complete(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function definition(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function links(DocumentRequest $request): array
    {
        return $this->result(__FUNCTION__) ?? [];
    }

    public function hover(array $params): ?array
    {
        return $this->firstResult(__FUNCTION__);
    }

    public function references(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function prepare(PositionedRequest $request): ?array
    {
        return $this->firstResult(__FUNCTION__);
    }

    public function rename(RenameRequest $request): ?array
    {
        return $this->firstResult(__FUNCTION__);
    }

    /** @return list<array<array-key, mixed>>|null */
    private function result(string $method): ?array
    {
        $this->calls[] = $method;

        return $this->result;
    }

    /** @return array<array-key, mixed>|null */
    private function firstResult(string $method): ?array
    {
        $results = $this->result($method);

        return null === $results ? null : ($results[0] ?? []);
    }
}
