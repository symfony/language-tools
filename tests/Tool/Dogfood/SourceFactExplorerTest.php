<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Metadata\MetadataSourceFacts;
use Symfony\Lsp\Feature\Metadata\MetadataSourceSymbol;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateSourceFacts;
use Symfony\Lsp\Tools\Dogfood\ScenarioPositionIndex;
use Symfony\Lsp\Tools\Dogfood\SourceFactExplorer;

/**
 * @phpstan-import-type Census from SourceFactExplorer
 * @phpstan-import-type CandidateGroup from SourceFactExplorer
 */
final class SourceFactExplorerTest extends TestCase
{
    public function testCountsEveryFactWhileSamplingABoundedNumberOfPositions(): void
    {
        $explorer = new SourceFactExplorer(positionsPerGroup: 2);
        foreach (['src/A.php', 'src/B.php', 'src/C.php'] as $file) {
            $explorer->add('routes', $file, new RouteSourceFacts('file:///'.$file, [], [
                $this->routeReference('first', 1),
                $this->routeReference('second', 2),
            ]));
        }

        $group = $this->group($explorer->census(), 'routes', RouteReference::class);

        self::assertSame(6, $group['count']);
        self::assertSame(6, $group['withPosition']);
        self::assertSame(6, $group['distinctPositions']);
        self::assertSame(3, $group['files']);
        self::assertSame([
            ['file' => 'src/A.php', 'line' => 1, 'character' => 4, 'scenario' => null],
            ['file' => 'src/A.php', 'line' => 2, 'character' => 4, 'scenario' => null],
        ], $group['positions']);
    }

    public function testSelectsTheSamePositionsWhateverTheOrderFilesAreIndexedIn(): void
    {
        $files = ['templates/a.twig', 'templates/b.twig', 'templates/c.twig', 'templates/d.twig'];
        $census = $this->explore($files, 2);
        $reversed = $this->explore(array_reverse($files), 2);

        self::assertSame($census, $reversed);
        self::assertSame(
            ['templates/a.twig', 'templates/b.twig'],
            array_column($this->group($census, 'templates', TemplateReference::class)['positions'], 'file'),
        );
    }

    public function testKeepsTheCensusCompleteWithoutAnyPosition(): void
    {
        $bounded = $this->explore(['templates/a.twig', 'templates/b.twig'], 0);
        $sampled = $this->explore(['templates/a.twig', 'templates/b.twig'], 3);

        $group = $this->group($bounded, 'templates', TemplateReference::class);
        self::assertSame([], $group['positions']);
        unset($group['positions']);
        $expected = $this->group($sampled, 'templates', TemplateReference::class);
        self::assertCount(2, $expected['positions']);
        unset($expected['positions']);
        self::assertSame($expected, $group);
    }

    public function testRejectsANegativeNumberOfPositions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceFactExplorer(positionsPerGroup: -1);
    }

    public function testReportsNeitherPayloadValuesNorSourceExcerpts(): void
    {
        $explorer = new SourceFactExplorer();
        $explorer->add('routes', 'src/SecretController.php', new RouteSourceFacts(
            'file:///src/SecretController.php',
            [new RouteDeclaration('secret_route_name', 'file:///src/SecretController.php', $this->range(1))],
            [new RouteReference('secret_reference_name', 'file:///src/SecretController.php', $this->range(2), 'App\\Secret\\Controller')],
        ));

        $census = (string) json_encode($explorer->census(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('secret_route_name', $census);
        self::assertStringNotContainsString('secret_reference_name', $census);
        self::assertStringNotContainsString('App\\\\Secret\\\\Controller', $census);
        self::assertStringNotContainsString('file:///', $census);
        self::assertStringContainsString((string) json_encode(RouteReference::class), $census);
    }

    public function testGroupsFactsByProviderAndKind(): void
    {
        $explorer = new SourceFactExplorer();
        $explorer->add('metadata', 'src/Product.php', new MetadataSourceFacts('file:///src/Product.php', [
            $this->metadataSymbol(MetadataSymbolKind::MappedClass, 1),
            $this->metadataSymbol(MetadataSymbolKind::Property, 2),
            $this->metadataSymbol(MetadataSymbolKind::Property, 3),
        ]));

        $census = $explorer->census();

        self::assertSame(
            [['metadata', MetadataSourceSymbol::class, 'MappedClass', 1], ['metadata', MetadataSourceSymbol::class, 'Property', 2]],
            array_map(static fn (array $group): array => [$group['provider'], $group['fact'], $group['kind'], $group['count']], $census['groups']),
        );
    }

    public function testFindsPositionsOnPublicRangePropertiesOfPlainFacts(): void
    {
        $explorer = new SourceFactExplorer();
        $explorer->add('templates', 'templates/a.twig', new TemplateSourceFacts(
            'file:///templates/a.twig',
            new TemplateDeclaration('a.twig', 'file:///templates/a.twig', $this->range(7)),
            [],
        ));

        $group = $this->group($explorer->census(), 'templates', TemplateDeclaration::class);

        self::assertNull($group['kind']);
        self::assertSame([['file' => 'templates/a.twig', 'line' => 7, 'character' => 4, 'scenario' => null]], $group['positions']);
    }

    public function testCountsRepeatedPositionsOnceAndSharedFactsOnce(): void
    {
        $shared = $this->routeReference('shared', 3);
        $explorer = new SourceFactExplorer();
        $explorer->add('routes', 'src/A.php', new RouteSourceFacts('file:///src/A.php', [], [
            $this->routeReference('twice', 1),
            $this->routeReference('twice', 1),
            $shared,
            $shared,
        ]));

        $group = $this->group($explorer->census(), 'routes', RouteReference::class);

        self::assertSame(3, $group['count']);
        self::assertSame(2, $group['distinctPositions']);
        self::assertSame([1, 3], array_column($group['positions'], 'line'));
    }

    public function testReportsWhenTheFactsAreDeeperThanTheExploredDepth(): void
    {
        $facts = new RouteSourceFacts('file:///src/A.php', [], [$this->routeReference('deep', 1)]);

        $explorer = new SourceFactExplorer(maximumDepth: 2);
        $explorer->add('routes', 'src/A.php', $facts);
        $shallow = $explorer->census();

        $explorer = new SourceFactExplorer(maximumDepth: 1);
        $explorer->add('routes', 'src/A.php', $facts);
        $truncated = $explorer->census();

        self::assertFalse($shallow['depthLimitReached']);
        self::assertSame(1, $shallow['facts']);
        self::assertTrue($truncated['depthLimitReached']);
        self::assertSame(0, $truncated['facts']);
    }

    public function testMarksTheCandidatesAScenarioAlreadyTargets(): void
    {
        $scenarios = new ScenarioPositionIndex([
            ['id' => 'route.reference', 'file' => 'src/A.php', 'position' => new Position(2, 6)],
            ['id' => 'route.elsewhere', 'file' => 'src/B.php', 'position' => new Position(9, 0)],
        ]);
        $explorer = new SourceFactExplorer(positionsPerGroup: 1, scenarios: $scenarios);
        $explorer->add('routes', 'src/A.php', new RouteSourceFacts('file:///src/A.php', [], [
            $this->routeReference('covered', 2),
            $this->routeReference('uncovered', 5),
        ]));

        $census = $explorer->census();
        $group = $this->group($census, 'routes', RouteReference::class);

        self::assertSame(1, $group['withScenario']);
        self::assertSame(['route.reference'], $group['scenarios']);
        self::assertSame(['covered' => ['route.reference'], 'uncovered' => ['route.elsewhere']], $census['scenarios']);
        self::assertSame([['file' => 'src/A.php', 'line' => 5, 'character' => 4, 'scenario' => null]], $group['positions']);
    }

    public function testReportsNoScenarioCoverageWithoutAManifest(): void
    {
        $explorer = new SourceFactExplorer();
        $explorer->add('routes', 'src/A.php', new RouteSourceFacts('file:///src/A.php', [], [$this->routeReference('any', 1)]));

        self::assertNull($explorer->census()['scenarios']);
    }

    /**
     * @param list<string> $files
     *
     * @return Census
     */
    private function explore(array $files, int $positionsPerGroup): array
    {
        $explorer = new SourceFactExplorer(positionsPerGroup: $positionsPerGroup);
        foreach ($files as $file) {
            $explorer->add('templates', $file, new TemplateSourceFacts('file:///'.$file, null, [
                new TemplateReference('base.html.twig', 'file:///'.$file, $this->range(4)),
            ]));
        }

        return $explorer->census();
    }

    /**
     * @param Census $census
     *
     * @return CandidateGroup
     */
    private function group(array $census, string $provider, string $fact): array
    {
        foreach ($census['groups'] as $group) {
            if ($provider === $group['provider'] && $fact === $group['fact']) {
                return $group;
            }
        }

        self::fail(\sprintf('No "%s" group for provider "%s".', $fact, $provider));
    }

    private function routeReference(string $name, int $line): RouteReference
    {
        return new RouteReference($name, 'file:///src/A.php', $this->range($line));
    }

    private function metadataSymbol(MetadataSymbolKind $kind, int $line): MetadataSourceSymbol
    {
        return new MetadataSourceSymbol($kind, 'name', 'file:///src/Product.php', $this->range($line), true);
    }

    private function range(int $line): Range
    {
        return new Range(new Position($line, 4), new Position($line, 12));
    }
}
