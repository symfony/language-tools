<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Stimulus\StimulusCodeActionProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusCodeLensProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusCompletionProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusController;
use Symfony\Lsp\Feature\Stimulus\StimulusDiagnosticProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusDocumentLinkProvider;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Feature\Stimulus\StimulusRelationshipProvider;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class StimulusProviderTest extends TestCase
{
    public function testSuggestsCloseStimulusControllerNames(): void
    {
        $uri = 'file:///workspace/templates/search.html.twig';
        $kit = (new ProjectTestKit())
            ->open($uri, '<div data-controller="searc"></div>', version: 2)
            ->index()
            ->runtime('stimulus', ['complete' => true, 'controllers' => [
                ['name' => 'search', 'sourcePath' => '/workspace/assets/controllers/search_controller.js', 'lazy' => false, 'vendor' => false],
            ]])
        ;
        $diagnostics = $kit->get(StimulusDiagnosticProvider::class)->diagnostics($kit->document($uri));
        self::assertIsArray($diagnostics);
        $actions = $kit->get(StimulusCodeActionProvider::class)->actions($kit->codeAction($uri, $diagnostics));

        self::assertSame(['Replace with "search"'], array_column($actions, 'title'));
        self::assertSame(['documentChanges' => [[
            'textDocument' => ['uri' => $uri, 'version' => 2],
            'edits' => [['range' => $diagnostics[0]['range'], 'newText' => 'search']],
        ]]], $actions[0]['edit'] ?? null);
    }

    public function testProvidesStimulusControllersActionsTargetsAndNavigation(): void
    {
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
        $featureControllerUri = 'file:///workspace/assets/Feature/controllers/feature-widget_controller.js';
        $featureControllerText = 'export default class extends Controller {}';
        $usageUri = 'file:///workspace/templates/search.html.twig';
        $usageText = <<<'TWIG'
            <div data-controller="search feature-widget missing"
                 data-action="click->search#open"
                 data-search-target="results">
            </div>
            {% set dataController = 'search' %}
            <div data-controller="{{ dataController }}"></div>
            <div data-controller="admin-{{ dataController }}"></div>
            <div data-controller="{{ dataController }}-admin"></div>
            {{ stimulus_action('search', 'open') }}
            {{ stimulus_controller('symfony/ux-autocomplete/autocomplete') }}
            {{ stimulus_controller('@symfony/ux-autocomplete/autocomplete') }}
            {{ stimulus_controller('symfony--ux-autocomplete--autocomplete') }}
            {{ stimulus_action('symfony/ux-autocomplete/autocomplete', 'onChange') }}
            {{ stimulus_target('symfony/ux-autocomplete/autocomplete', 'field') }}
            TWIG;
        $kit = (new ProjectTestKit())
            ->open($controllerUri, $controllerText)
            ->open($featureControllerUri, $featureControllerText)
            ->open($usageUri, $usageText)
            ->index()
        ;
        $kit->get(StimulusIndexRegistry::class)->forProject($kit->project())->replace(
            true,
            new StimulusController(
                'search',
                '/workspace/assets/controllers/search_controller.js',
                true,
                false,
                ['open'],
                ['input', 'results'],
                ['url'],
                [],
                [],
            ),
            new StimulusController(
                'symfony--ux-autocomplete--autocomplete',
                '/workspace/vendor/symfony/ux-autocomplete/assets/dist/controller.js',
                true,
                true,
                ['onChange'],
                ['field'],
                [],
                [],
                [],
            ),
        );
        $completionProvider = $kit->get(StimulusCompletionProvider::class);
        $relationshipProvider = $kit->get(StimulusRelationshipProvider::class);

        self::assertSame(['search'], $this->completeAtEnd($kit, 'file:///workspace/templates/controller_completion.html.twig', '<div data-controller="sea'));
        $packageControllerCompletionUri = 'file:///workspace/templates/package_controller_completion.html.twig';
        $packageControllerCompletionText = "{{ stimulus_controller('@symfony/ux-auto";
        $kit->open($packageControllerCompletionUri, $packageControllerCompletionText);
        $packageControllerCompletion = $completionProvider->complete($kit->positioned($kit->offset($packageControllerCompletionUri, \strlen($packageControllerCompletionText))));
        self::assertSame(['symfony--ux-autocomplete--autocomplete'], $kit->labels($packageControllerCompletion));
        self::assertSame(['@symfony/ux-auto'], array_column($packageControllerCompletion, 'filterText'));
        self::assertSame(['open'], $this->completeAtEnd($kit, 'file:///workspace/templates/action_completion.html.twig', '<button data-action="click->search#op'));
        self::assertSame(['results'], $this->completeAtEnd($kit, 'file:///workspace/templates/target_completion.html.twig', '<input data-search-target="res'));
        self::assertSame(['onChange'], $this->completeAtEnd($kit, 'file:///workspace/templates/package_action_completion.html.twig', "{{ stimulus_action('@symfony/ux-autocomplete/autocomplete', 'on"));
        self::assertSame(['field'], $this->completeAtEnd($kit, 'file:///workspace/templates/package_target_completion.html.twig', "{{ stimulus_target('symfony/ux-autocomplete/autocomplete', 'fi"));
        $quotedAttributeUri = 'file:///workspace/templates/quoted_attribute.html.twig';
        $quotedAttributeText = '{% set markup = \'<button data-action="click->search#op';
        $kit->open($quotedAttributeUri, $quotedAttributeText);
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->offset($quotedAttributeUri, \strlen($quotedAttributeText)))));
        $markupHelperUri = 'file:///workspace/templates/markup_helper.html.twig';
        $markupHelperText = "<p>Call stimulus_controller('sea";
        $kit->open($markupHelperUri, $markupHelperText);
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->offset($markupHelperUri, \strlen($markupHelperText)))));

        $actionParams = $kit->offset($usageUri, strpos($usageText, '#open') + 2);
        self::assertSame([$controllerUri], $kit->targets($relationshipProvider->definition($kit->positioned($actionParams))));
        self::assertCount(3, $relationshipProvider->references($kit->references($actionParams)));
        self::assertSame('Stimulus action: `search#open`', $kit->hoverText($relationshipProvider->hover($kit->positioned($actionParams))));
        $unknownActionUri = 'file:///workspace/templates/unknown_action.html.twig';
        $unknownActionText = '<button data-action="search#missing"></button>';
        $kit->open($unknownActionUri, $unknownActionText);
        $unknownActionParams = $kit->offset($unknownActionUri, strpos($unknownActionText, 'missing') + 2);
        self::assertNull($relationshipProvider->hover($kit->positioned($unknownActionParams)));
        self::assertSame([], $relationshipProvider->definition($kit->positioned($unknownActionParams)));

        $packageControllerParams = $kit->offset($usageUri, strpos($usageText, 'symfony/ux-autocomplete/autocomplete') + 2);
        self::assertSame(
            ['file:///workspace/vendor/symfony/ux-autocomplete/assets/dist/controller.js'],
            $kit->targets($relationshipProvider->definition($kit->positioned($packageControllerParams))),
        );
        self::assertCount(5, $relationshipProvider->references($kit->references($packageControllerParams)));
        foreach (['onChange', 'field'] as $member) {
            self::assertSame(
                ['file:///workspace/vendor/symfony/ux-autocomplete/assets/dist/controller.js'],
                $kit->targets($relationshipProvider->definition($kit->positioned($kit->offset($usageUri, strpos($usageText, $member) + 2)))),
            );
        }

        $diagnostics = $kit->get(StimulusDiagnosticProvider::class)->diagnostics($kit->document($usageUri));
        self::assertSame(['stimulus.unknown_controller'], $kit->codes($diagnostics));
        self::assertSame(['Unknown Stimulus controller "missing".'], $kit->messages($diagnostics));
        self::assertGreaterThanOrEqual(4, \count($kit->get(StimulusDocumentLinkProvider::class)->links($kit->document($usageUri))));
        self::assertSame('3 Stimulus controller usages', $kit->titles($kit->get(StimulusCodeLensProvider::class)->codeLenses($kit->document($controllerUri)))[0] ?? null);
    }

    public function testRecognizesManuallyRegisteredControllers(): void
    {
        $bootstrapUri = 'file:///workspace/assets/app/stimulus_bootstrap.js';
        $bootstrapText = <<<'JS'
            import { startStimulusApp } from '@symfony/stimulus-bundle';

            const app = startStimulusApp();

            import Clipboard from 'stimulus-clipboard';

            app.register('clipboard', Clipboard);
            JS;
        $usageUri = 'file:///workspace/templates/episode/tracked.html.twig';
        $usageText = '<div data-controller="clipboard"></div>';
        $kit = (new ProjectTestKit())
            ->open($bootstrapUri, $bootstrapText)
            ->open($usageUri, $usageText)
            ->index()
            ->runtime('stimulus', ['complete' => true, 'controllers' => []])
        ;

        self::assertSame([], $kit->get(StimulusDiagnosticProvider::class)->diagnostics($kit->document($usageUri)));
        self::assertSame(['clipboard'], $this->completeAtEnd($kit, 'file:///workspace/templates/completion.html.twig', '<div data-controller="clip'));
        self::assertSame([$bootstrapUri], $kit->targets($kit->get(StimulusRelationshipProvider::class)->definition($kit->positioned($kit->offset($usageUri, strpos($usageText, 'clipboard') + 2)))));
    }

    /** @return list<mixed> */
    private function completeAtEnd(ProjectTestKit $kit, string $uri, string $text): array
    {
        $kit->open($uri, $text);

        return $kit->labels($kit->get(StimulusCompletionProvider::class)->complete($kit->positioned($kit->offset($uri, \strlen($text)))));
    }
}
