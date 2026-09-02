<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\JavaScriptSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusCodeLensProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusCompletionContextResolver;
use Symfony\Lsp\Feature\Stimulus\StimulusCompletionProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusController;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusDiagnosticProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusDocumentLinkProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Feature\Stimulus\StimulusReferenceExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusRelationshipProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusResolver;
use Symfony\Lsp\Feature\Stimulus\StimulusSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusProviderTest extends TestCase
{
    public function testProvidesStimulusControllersActionsTargetsAndNavigation(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $converter = new PositionConverter();
        $comments = new TwigCommentParser();
        $codeMasker = new JavaScriptSourceAnalyzer();
        $extractor = new StimulusExtractor(
            new StimulusControllerExtractor($converter, new ProjectPathResolver(new UriToPathConverter()), $codeMasker),
            new StimulusReferenceExtractor($converter, $comments, $codeMasker),
            new StimulusCompletionContextResolver($converter, $comments),
        );
        $controllerUri = 'file:///workspace/assets/controllers/search_controller.js';
        $controllerText = <<<'JS'
            import { Controller } from '@hotwired/stimulus';

            /* stimulusFetch: 'lazy' */
            export default class extends Controller {
                static targets = ['input', 'results'];
                static values = { url: String };

                connect() {
                }

                open() {
                }
            }
            JS;
        $usageUri = 'file:///workspace/templates/search.html.twig';
        $usageText = <<<'TWIG'
            <div data-controller="search missing"
                 data-action="click->search#open"
                 data-search-target="results">
            </div>
            {% set dataController = 'search' %}
            <div data-controller="{{ dataController }}"></div>
            <div data-controller="admin-{{ dataController }}"></div>
            <div data-controller="{{ dataController }}-admin"></div>
            {{ stimulus_action('search', 'open') }}
            TWIG;
        $documents = new DocumentStore();
        $documents->open(new Document($controllerUri, 'javascript', 1, $controllerText));
        $documents->open(new Document($usageUri, 'twig', 1, $usageText));
        $controllerCompletionUri = 'file:///workspace/templates/controller_completion.html.twig';
        $controllerCompletionText = '<div data-controller="sea';
        $documents->open(new Document($controllerCompletionUri, 'twig', 1, $controllerCompletionText));
        $actionCompletionUri = 'file:///workspace/templates/action_completion.html.twig';
        $actionCompletionText = '<button data-action="click->search#op';
        $documents->open(new Document($actionCompletionUri, 'twig', 1, $actionCompletionText));
        $targetCompletionUri = 'file:///workspace/templates/target_completion.html.twig';
        $targetCompletionText = '<input data-search-target="res';
        $documents->open(new Document($targetCompletionUri, 'twig', 1, $targetCompletionText));
        $unknownActionUri = 'file:///workspace/templates/unknown_action.html.twig';
        $unknownActionText = '<button data-action="search#missing"></button>';
        $documents->open(new Document($unknownActionUri, 'twig', 1, $unknownActionText));
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new StimulusIndexRegistry();
        $indexes->forProject($project)->replace(true, new StimulusController(
            'search',
            '/workspace/assets/controllers/search_controller.js',
            true,
            false,
            ['open'],
            ['input', 'results'],
            ['url'],
            [],
            [],
        ));
        $sourceIndexes = new StimulusSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract($project, new SourceDocument($controllerUri, 'javascript', $controllerText)),
            $extractor->extract($project, new SourceDocument($usageUri, 'twig', $usageText)),
        );
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $uriConverter = new UriToPathConverter();
        $protocol = new LspProtocolMapper();
        $stimulus = new StimulusResolver($documentResolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor);
        $completionProvider = new StimulusCompletionProvider($documentResolver, $converter, $protocol, $extractor, $stimulus);
        $relationshipProvider = new StimulusRelationshipProvider($uriConverter, $protocol, $indexes, $sourceIndexes, $stimulus);
        $diagnosticProvider = new StimulusDiagnosticProvider($documentResolver, $protocol, $indexes, $extractor, $stimulus);
        $documentLinkProvider = new StimulusDocumentLinkProvider($documentResolver, $uriConverter, $protocol, $indexes, $extractor, $stimulus);
        $codeLensProvider = new StimulusCodeLensProvider($documentResolver, $protocol, $sourceIndexes, $extractor);

        self::assertSame(['search'], array_column($completionProvider->complete($this->params($converter, $controllerCompletionUri, $controllerCompletionText, \strlen($controllerCompletionText))) ?? [], 'label'));
        self::assertSame(['open'], array_column($completionProvider->complete($this->params($converter, $actionCompletionUri, $actionCompletionText, \strlen($actionCompletionText))) ?? [], 'label'));
        self::assertSame(['results'], array_column($completionProvider->complete($this->params($converter, $targetCompletionUri, $targetCompletionText, \strlen($targetCompletionText))) ?? [], 'label'));

        $actionParams = $this->params($converter, $usageUri, $usageText, strpos($usageText, '#open') + 2);
        self::assertSame([$controllerUri], array_column($relationshipProvider->definition($actionParams) ?? [], 'uri'));
        self::assertCount(3, $relationshipProvider->references($actionParams) ?? []);
        $hover = $relationshipProvider->hover($actionParams);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertSame('Stimulus action: `search#open`', $hover['contents']['value'] ?? null);
        $unknownActionParams = $this->params($converter, $unknownActionUri, $unknownActionText, strpos($unknownActionText, 'missing') + 2);
        self::assertNull($relationshipProvider->hover($unknownActionParams));
        self::assertSame([], $relationshipProvider->definition($unknownActionParams));

        $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $usageUri]]) ?? [];
        self::assertSame(['stimulus.unknown_controller'], array_column($diagnostics, 'code'));
        self::assertSame(['Unknown Stimulus controller "missing".'], array_column($diagnostics, 'message'));
        self::assertGreaterThanOrEqual(4, \count($documentLinkProvider->links(['textDocument' => ['uri' => $usageUri]]) ?? []));
        $lenses = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $controllerUri]]);
        self::assertIsArray($lenses);
        self::assertIsArray($lenses[0]['command'] ?? null);
        self::assertSame('3 Stimulus controller usages', $lenses[0]['command']['title'] ?? null);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];
    }
}
