<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\Twig\LiveComponentEventProvider;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentCompletionProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentPhpExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentRelationshipProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentTemplateExtractor;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Tests\Support\LspRequests;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\ProviderRequests;

final class LiveComponentProviderTest extends TestCase
{
    public function testProvidesLivePropertiesActionsAndEvents(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = $this->extractor($converter, $commentParser);
        $classUri = 'file:///workspace/src/Twig/Components/Search.php';
        $classText = <<<'PHP'
            <?php
            namespace App\Twig\Components;

            use Symfony\UX\LiveComponent\Attribute\AsLiveComponent as Component;
            use Symfony\UX\LiveComponent\Attribute\LiveAction as Action;
            use Symfony\UX\LiveComponent\Attribute\LiveListener as Listener;
            use Symfony\UX\LiveComponent\Attribute\LiveProp as Property;

            #[Component(name: 'Search', template: 'components/Search.html.twig')]
            final class Search
            {
                #[Property]
                private string $query = '';

                #[Action]
                public function submit(): void
                {
                    $this->emit('search:completed');
                }

                #[Listener('search:completed')]
                public function refresh(): void
                {
                }
            }
            PHP;
        $templateUri = 'file:///workspace/templates/components/Search.html.twig';
        $templateText = "<twig:Button data-live-action-param=\"submit\" />\n{{ live_action('submit') }}";
        $usageUri = 'file:///workspace/templates/page.html.twig';
        $usageText = '<twig:Search query="term" data-live-action-param="submit" />';
        $completionUri = 'file:///workspace/templates/completion.html.twig';
        $completionText = '<twig:Search data-live-action-param="sub';
        $documents = new DocumentStore();
        $documents->open(new Document($classUri, 'php', 1, $classText));
        $documents->open(new Document($templateUri, 'twig', 1, $templateText));
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $documents->open(new Document($completionUri, 'twig', 1, $completionText));
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace(
            $extractor->extract($project, new SourceDocument($classUri, 'php', $classText)),
            $extractor->extract($project, new SourceDocument($templateUri, 'twig', $templateText)),
            $extractor->extract($project, new SourceDocument($usageUri, 'twig', $usageText)),
        );
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $componentResolver = new TwigComponentResolver(new PositionedSourceSymbolResolver($converter), $indexes, new TemplateIndexRegistry(new DependencyInjectionSourceIndexRegistry()), $extractor);
        $completionProvider = new TwigComponentCompletionProvider($converter, $protocol, $indexes, $componentResolver, $commentParser);
        $relationshipProvider = new TwigComponentRelationshipProvider(new LspRequestFactory($documents, $projects, $converter), $protocol, $indexes, $componentResolver);

        self::assertSame(['submit'], array_column($completionProvider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($completionUri, $completionText, \strlen($completionText)))), 'label'));
        $nestedActionOffset = strpos($templateText, 'submit') + \strlen('sub');
        self::assertSame(['submit'], array_column($completionProvider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($templateUri, $templateText, $nestedActionOffset))), 'label'));
        $actionParams = LspRequests::offset($usageUri, $usageText, strpos($usageText, 'submit') + 2);
        self::assertSame([$classUri], array_column($relationshipProvider->definition((new ProviderRequests($documents, $projects))->positioned($actionParams)), 'uri'));
        self::assertCount(4, $relationshipProvider->references((new ProviderRequests($documents, $projects))->references($actionParams)));
        $actionHover = $relationshipProvider->hover($actionParams);
        self::assertIsArray($actionHover);
        self::assertIsArray($actionHover['contents'] ?? null);
        self::assertSame('Live action: `Search#submit`', $actionHover['contents']['value'] ?? null);

        $componentParams = LspRequests::offset($usageUri, $usageText, strpos($usageText, 'Search') + 2);
        $componentHover = $relationshipProvider->hover($componentParams);
        self::assertIsArray($componentHover);
        self::assertIsArray($componentHover['contents'] ?? null);
        self::assertIsString($componentHover['contents']['value'] ?? null);
        self::assertStringContainsString('Live component: `Search`', $componentHover['contents']['value']);
        self::assertStringContainsString('Properties: `query`', $componentHover['contents']['value']);
        self::assertStringContainsString('Actions: `submit`, `refresh`', $componentHover['contents']['value']);

        foreach (["{{ live_action(actionName: 'sub", "{{ live_action(actionName = 'sub"] as $version => $namedActionCompletionText) {
            $documents->update($templateUri, $version + 2, $namedActionCompletionText);
            self::assertSame(
                ['submit'],
                array_column($completionProvider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($templateUri, $namedActionCompletionText, \strlen($namedActionCompletionText)))), 'label'),
            );
        }

        $eventProvider = new LiveComponentEventProvider(new LspRequestFactory($documents, $projects, $converter), $converter, new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $indexes, $extractor, new PhpCommentParser(), new TolerantPhpParser(new Parser()));
        self::assertSame(['search:completed'], array_column($eventProvider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($classUri, $classText, strpos($classText, "emit('search:co") + \strlen("emit('search:co")))), 'label'));
        $eventParams = LspRequests::offset($classUri, $classText, strpos($classText, "emit('search:completed") + \strlen("emit('search:"));
        self::assertSame([$classUri], array_column($eventProvider->definition((new ProviderRequests($documents, $projects))->positioned($eventParams)), 'uri'));
        self::assertCount(2, $eventProvider->references((new ProviderRequests($documents, $projects))->references($eventParams)));
        $eventHover = $eventProvider->hover($eventParams);
        self::assertIsArray($eventHover);
        self::assertIsArray($eventHover['contents'] ?? null);
        self::assertIsString($eventHover['contents']['value'] ?? null);
        self::assertStringContainsString('Listener: `Search#refresh`', $eventHover['contents']['value']);
    }

    public function testCompletesOnlyEmitCallsOnTheirOwningLiveComponents(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $uri = 'file:///workspace/src/Twig/Components/Events.php';
        $text = <<<'PHP'
            <?php
            namespace App\Twig\Components;

            use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
            use Symfony\UX\LiveComponent\Attribute\LiveListener;

            #[AsLiveComponent(name: 'Search')]
            final class Search
            {
                #[LiveListener('search:completed')]
                public function refresh(): void
                {
                }

                public function submit(): void
                {
                    $this->emit('search:completed');
                    $this->emit(event: 'search:completed');
                    $bus->emit('search:completed');
                    emit('search:completed');
                }
            }

            final class Helper
            {
                public function submit(): void
                {
                    $this->emit('search:completed');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace($extractor->extract($project, new SourceDocument($uri, 'php', $text)));
        $provider = new LiveComponentEventProvider(new LspRequestFactory($documents, $projects, $converter), $converter, new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $indexes, $extractor, new PhpCommentParser(), new TolerantPhpParser(new Parser()));

        /** @var list<array{string, list<string>, bool}> $cases */
        $cases = [
            ['$this->emit(\'search:c', ['search:completed'], false],
            ['$this->emit(event: \'search:c', ['search:completed'], false],
            ['$bus->emit(\'search:c', [], false],
            ["\n                    emit('search:c", [], false],
            ['$this->emit(\'search:c', [], true],
        ];
        foreach ($cases as [$needle, $expected, $last]) {
            $offset = (int) ($last ? strrpos($text, $needle) : strpos($text, $needle)) + \strlen($needle);

            self::assertSame($expected, array_column($provider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($uri, $text, $offset))), 'label'));
        }
    }

    public function testRecoversComponentFromIncompletePhp(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $facts = $extractor->extract($project, new SourceDocument('file:///workspace/src/Twig/Components/Card.php', 'php', <<<'PHP'
            <?php
            use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

            #[AsTwigComponent(name: 'Card')
            final class Card
            {
                public string $title;
            PHP));

        self::assertSame(['Card'], array_map(static fn ($component): string => $component->name, $facts->components));
        self::assertSame(['title'], $facts->components[0]->properties);
    }

    private function extractor(PositionConverter $converter, ?TwigCommentParser $comments = null): TwigComponentExtractor
    {
        $comments ??= new TwigCommentParser();
        $names = new TwigComponentNameResolver(new TemplateNameResolver(ProjectPaths::resolver()));

        return new TwigComponentExtractor(
            new TolerantPhpParser(new Parser()),
            new TwigComponentPhpExtractor($converter, $names),
            new TwigComponentTemplateExtractor(
                $converter,
                $names,
                new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments, new TwigDirectiveLocator()),
                new TwigCallArgumentResolver(new TwigArgumentParser()),
            ),
        );
    }

    public function testOffersNoEmitCompletionsInsidePhpComments(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $uri = 'file:///workspace/src/Twig/Components/Search.php';
        $text = <<<'PHP'
            <?php
            use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

            #[AsLiveComponent(name: 'Search')]
            final class Search
            {
                public function submit(): void
                {
                    // $this->emit('search:c
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replace($extractor->extract($project, new SourceDocument($uri, 'php', $text)));
        $provider = new LiveComponentEventProvider(new LspRequestFactory($documents, $projects, $converter), $converter, new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $indexes, $extractor, new PhpCommentParser(), new TolerantPhpParser(new Parser()));

        self::assertSame([], $provider->complete((new ProviderRequests($documents, $projects))->positioned(LspRequests::offset($uri, $text, strpos($text, 'search:c') + \strlen('search:c')))));
    }

    public function testAttributesEmitCallsToTheirOwningLiveComponents(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);

        $facts = $extractor->extract(new Project('/workspace', 'file:///workspace'), new SourceDocument('file:///workspace/src/Twig/Components/Events.php', 'php', <<<'PHP'
            <?php
            namespace App\Twig\Components;

            use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

            #[AsLiveComponent(name: 'Alpha')]
            final class Alpha
            {
                public function submit(): void
                {
                    $this->emit('alpha:done');
                    $this->emit(event: 'alpha:named');
                    $bus->emit('ignored:receiver');
                }
            }

            #[AsLiveComponent(name: 'Beta')]
            final class Beta
            {
                public function submit(): void
                {
                    $this->emit('beta:done');
                }
            }

            final class Helper
            {
                public function submit(): void
                {
                    $this->emit('ignored:class');
                }
            }
            PHP));

        self::assertSame(
            [
                ['alpha:done', 'Alpha'],
                ['alpha:named', 'Alpha'],
                ['beta:done', 'Beta'],
            ],
            array_map(static fn ($event): array => [$event->name, $event->component], $facts->events),
        );
    }

    public function testIgnoresEmitCallsInPhpComments(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);

        $facts = $extractor->extract(new Project('/workspace', 'file:///workspace'), new SourceDocument('file:///workspace/src/Twig/Components/Search.php', 'php', <<<'PHP'
            <?php
            namespace App\Twig\Components;

            use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
            use Symfony\UX\LiveComponent\Attribute\LiveAction;

            #[AsLiveComponent(name: 'Search', template: 'components/Search.html.twig')]
            final class Search
            {
                #[LiveAction]
                public function submit(): void
                {
                    // $this->emit('commented:event');
                    $this->emit('live:event');
                }
            }
            PHP));

        self::assertSame(['live:event'], array_map(static fn ($event): string => $event->name, $facts->events));
    }
}
