<?php

namespace Symfony\Lsp\Tests\Feature;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Project\Project;

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

    public function testDocumentLinkProvidersAggregateInOrderAndDistinguishNoMatchFromEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['target' => 'file:///second']]);
        $third = new StubProvider([['target' => 'file:///third']]);

        self::assertSame(
            [['target' => 'file:///second'], ['target' => 'file:///third']],
            (new DocumentLinkProviderRegistry([$first, $second, $third]))->links([]),
        );
        self::assertSame(['links'], $first->calls);
        self::assertSame(['links'], $second->calls);
        self::assertSame(['links'], $third->calls);
        self::assertNull((new DocumentLinkProviderRegistry([new StubProvider(null)]))->links([]));
        self::assertSame([], (new DocumentLinkProviderRegistry([new StubProvider([])]))->links([]));
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

    public function testCodeLensProvidersAggregateAllMatchesAndAlwaysReturnAList(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['command' => ['title' => 'second']]]);
        $third = new StubProvider([['command' => ['title' => 'third']]]);

        self::assertSame(
            [['command' => ['title' => 'second']], ['command' => ['title' => 'third']]],
            (new CodeLensProviderRegistry([$first, $second, $third]))->codeLenses([]),
        );
        self::assertSame(['codeLenses'], $first->calls);
        self::assertSame(['codeLenses'], $second->calls);
        self::assertSame(['codeLenses'], $third->calls);
        self::assertSame([], (new CodeLensProviderRegistry([new StubProvider(null)]))->codeLenses([]));
    }

    public function testHoverProvidersReturnTheFirstMatchIncludingAnEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['contents' => 'second']]);
        $third = new StubProvider([['contents' => 'third']]);

        self::assertSame(
            ['contents' => 'second'],
            (new HoverProviderRegistry([$first, $second, $third]))->hover([]),
        );
        self::assertSame(['hover'], $first->calls);
        self::assertSame(['hover'], $second->calls);
        self::assertSame([], $third->calls);
        self::assertNull((new HoverProviderRegistry([new StubProvider(null)]))->hover([]));

        $afterEmpty = new StubProvider([['contents' => 'later']]);
        self::assertSame([], (new HoverProviderRegistry([new StubProvider([[]]), $afterEmpty]))->hover([]));
        self::assertSame([], $afterEmpty->calls);
    }

    public function testRenamePreparationReturnsTheFirstMatchIncludingAnEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['placeholder' => 'second']]);
        $third = new StubProvider([['placeholder' => 'third']]);

        self::assertSame(
            ['placeholder' => 'second'],
            (new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [$first, $second, $third]))->prepare([]),
        );
        self::assertSame(['prepare'], $first->calls);
        self::assertSame(['prepare'], $second->calls);
        self::assertSame([], $third->calls);
        self::assertNull((new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [new StubProvider(null)]))->prepare([]));

        $afterEmpty = new StubProvider([['placeholder' => 'later']]);
        self::assertSame([], (new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [new StubProvider([[]]), $afterEmpty]))->prepare([]));
        self::assertSame([], $afterEmpty->calls);
    }

    public function testRenameProvidersReturnTheFirstMatchIncludingAnEmptyMatch(): void
    {
        $first = new StubProvider(null);
        $second = new StubProvider([['changes' => ['second']]]);
        $third = new StubProvider([['changes' => ['third']]]);

        self::assertSame(
            ['changes' => ['second']],
            (new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [$first, $second, $third]))->rename([]),
        );
        self::assertSame(['rename'], $first->calls);
        self::assertSame(['rename'], $second->calls);
        self::assertSame([], $third->calls);
        self::assertNull((new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [new StubProvider(null)]))->rename([]));

        $afterEmpty = new StubProvider([['changes' => ['later']]]);
        self::assertSame([], (new RenameProviderRegistry(new SourceOverlayHealthRegistry(), [new StubProvider([[]]), $afterEmpty]))->rename([]));
        self::assertSame([], $afterEmpty->calls);
    }

    /** @param array<array-key, mixed> $edit */
    #[DataProvider('workspaceEditProvider')]
    public function testRenameRefusesWorkspaceEditsTargetingADegradedDocument(array $edit): void
    {
        $health = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $health->record($project, 'file:///workspace/src/Target.php', SourceParseHealth::Partial);
        $provider = new StubProvider([$edit]);

        try {
            (new RenameProviderRegistry($health, [$provider]))->rename([]);
            self::fail('The rename should have been refused.');
        } catch (JsonRpcException $error) {
            self::assertSame('Rename is unavailable while an affected open PHP document contains syntax errors.', $error->getMessage());
            self::assertSame(['rename'], $provider->calls);
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function workspaceEditProvider(): iterable
    {
        yield 'document changes' => [[
            'documentChanges' => [[
                'textDocument' => ['uri' => 'file:///workspace/src/Target.php', 'version' => null],
                'edits' => [],
            ]],
        ]];
        yield 'changes' => [[
            'changes' => ['file:///workspace/src/Target.php' => []],
        ]];
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

    public function codeLenses(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function complete(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function definition(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function links(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function hover(array $params): ?array
    {
        return $this->firstResult(__FUNCTION__);
    }

    public function references(array $params): ?array
    {
        return $this->result(__FUNCTION__);
    }

    public function prepare(array $params): ?array
    {
        return $this->firstResult(__FUNCTION__);
    }

    public function rename(array $params): ?array
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
