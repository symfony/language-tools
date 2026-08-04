<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\LiveComponentEventProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentProvider;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class LiveComponentProviderTest extends TestCase
{
    public function testProvidesLivePropertiesActionsAndEvents(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $converter = new PositionConverter();
        $extractor = new TwigComponentExtractor($converter);
        $classUri = 'file:///workspace/src/Twig/Components/Search.php';
        $classText = <<<'PHP'
            <?php
            namespace App\Twig\Components;

            #[AsLiveComponent(name: 'Search', template: 'components/Search.html.twig')]
            final class Search
            {
                #[LiveProp]
                private string $query = '';

                #[LiveAction]
                public function submit(): void
                {
                    $this->emit('search:completed');
                }

                #[LiveListener('search:completed')]
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
            $extractor->extract($project, $classUri, 'php', $classText),
            $extractor->extract($project, $templateUri, 'twig', $templateText),
            $extractor->extract($project, $usageUri, 'twig', $usageText),
        );
        $provider = new TwigComponentProvider($documents, $projects, $converter, $indexes, $extractor);

        self::assertSame(['submit'], array_column($provider->complete($this->params($converter, $completionUri, $completionText, \strlen($completionText))) ?? [], 'label'));
        $nestedActionOffset = strpos($templateText, 'submit') + \strlen('sub');
        self::assertSame(['submit'], array_column($provider->complete($this->params($converter, $templateUri, $templateText, $nestedActionOffset)) ?? [], 'label'));
        $actionParams = $this->params($converter, $usageUri, $usageText, strpos($usageText, 'submit') + 2);
        self::assertSame([$classUri], array_column($provider->definition($actionParams) ?? [], 'uri'));
        self::assertCount(4, $provider->references($actionParams) ?? []);
        $actionHover = $provider->hover($actionParams);
        self::assertIsArray($actionHover);
        self::assertIsArray($actionHover['contents'] ?? null);
        self::assertSame('Live action: `Search#submit`', $actionHover['contents']['value'] ?? null);

        $componentParams = $this->params($converter, $usageUri, $usageText, strpos($usageText, 'Search') + 2);
        $componentHover = $provider->hover($componentParams);
        self::assertIsArray($componentHover);
        self::assertIsArray($componentHover['contents'] ?? null);
        self::assertIsString($componentHover['contents']['value'] ?? null);
        self::assertStringContainsString('Live component: `Search`', $componentHover['contents']['value']);
        self::assertStringContainsString('Properties: `query`', $componentHover['contents']['value']);
        self::assertStringContainsString('Actions: `submit`, `refresh`', $componentHover['contents']['value']);

        $eventProvider = new LiveComponentEventProvider(new DocumentContextResolver($documents, $projects), $converter, $indexes, $extractor);
        self::assertSame(['search:completed'], array_column($eventProvider->complete($this->params($converter, $classUri, $classText, strpos($classText, "emit('search:co") + \strlen("emit('search:co"))) ?? [], 'label'));
        $eventParams = $this->params($converter, $classUri, $classText, strpos($classText, "emit('search:completed") + \strlen("emit('search:"));
        self::assertSame([$classUri], array_column($eventProvider->definition($eventParams) ?? [], 'uri'));
        self::assertCount(2, $eventProvider->references($eventParams) ?? []);
        $eventHover = $eventProvider->hover($eventParams);
        self::assertIsArray($eventHover);
        self::assertIsArray($eventHover['contents'] ?? null);
        self::assertIsString($eventHover['contents']['value'] ?? null);
        self::assertStringContainsString('Listener: `Search#refresh`', $eventHover['contents']['value']);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];
    }
}
