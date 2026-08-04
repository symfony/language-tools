import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    hoverText,
    labels,
    locationPaths,
    open,
    positionAfter,
    positionInside,
    TestCase,
    waitFor,
    withTemporaryDocument,
} from './support';

export const stimulusTests: TestCase[] = [
    ['Stimulus controllers and members complete, navigate and diagnose references', testStimulusLanguageFeatures],
    ['Live component actions and events complete and navigate references', testLiveComponentLanguageFeatures],
];

async function testStimulusLanguageFeatures(): Promise<void> {
    const contents = `<div data-controller="sea"></div>
<button data-controller="search missing"
        data-action="click->search#op click->search#open"
        data-search-target="res results">
</button>
`;
    await withTemporaryDocument('stimulus.html.twig', contents, async (document) => {
        const controllerCompletionPosition = positionAfter(document, 'data-controller="sea');
        const controllerItems = await waitFor(
            () => completions(document.uri, controllerCompletionPosition),
            (result) => labels(result).includes('search'),
            'Stimulus controller completion',
        );
        assert.ok(labels(controllerItems).includes('search'));

        const actionCompletionPosition = positionAfter(document, 'click->search#op');
        const actionItems = await waitFor(
            () => completions(document.uri, actionCompletionPosition),
            (result) => labels(result).includes('open'),
            'Stimulus action completion',
        );
        assert.ok(labels(actionItems).includes('open'));

        const targetCompletionPosition = positionAfter(document, 'data-search-target="res');
        const targetItems = await waitFor(
            () => completions(document.uri, targetCompletionPosition),
            (result) => labels(result).includes('results'),
            'Stimulus target completion',
        );
        assert.ok(labels(targetItems).includes('results'));

        const actionPosition = positionInside(document, 'click->search#open', 'open');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, actionPosition);
        assert.match(hoverText(hovers), /Stimulus action: `search#open`/);
        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, actionPosition);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/assets/controllers/search_controller.js')));
        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, actionPosition);
        assert.ok(locationPaths(references).includes(document.uri.path));

        const links = await vscode.commands.executeCommand<vscode.DocumentLink[]>('vscode.executeLinkProvider', document.uri);
        assert.ok(links.some((item) => item.target?.path.endsWith('/assets/controllers/search_controller.js')));

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (result) => result.some((item) => 'stimulus.unknown_controller' === item.code),
            'unknown Stimulus controller diagnostic',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('missing')));
    }, 'twig');

    const controller = await open('assets/controllers/search_controller.js');
    const lenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', controller.uri),
        (result) => (result ?? []).some((item) => item.command?.title.includes('Stimulus controller usage')),
        'Stimulus controller code lens',
    );
    assert.ok(lenses.some((item) => item.command?.title.includes('Stimulus controller usage')));
}

async function testLiveComponentLanguageFeatures(): Promise<void> {
    const contents = '<twig:Search data-live-action-param="sub" />\n';
    await withTemporaryDocument('live_component.html.twig', contents, async (document) => {
        const completionPosition = positionAfter(document, 'data-live-action-param="sub');
        const items = await waitFor(
            () => completions(document.uri, completionPosition),
            (result) => labels(result).includes('submit'),
            'Live component action completion',
        );
        assert.ok(labels(items).includes('submit'));
    }, 'twig');

    const fixture = await open('templates/fixture.html.twig');
    const actionPosition = positionInside(fixture, 'data-live-action-param="submit"', 'submit');
    const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', fixture.uri, actionPosition);
    assert.match(hoverText(hovers), /Live action: `Search#submit`/);
    const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', fixture.uri, actionPosition);
    assert.ok(locationPaths(definitions).some((item) => item.endsWith('/src/Twig/Components/Search.php')));
    const actionReferences = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', fixture.uri, actionPosition);
    assert.ok(locationPaths(actionReferences).some((item) => item.endsWith('/templates/components/Search.html.twig')));

    const component = await open('src/Twig/Components/Search.php');
    const eventCompletionPosition = positionAfter(component, "emit('search:co");
    const eventItems = await waitFor(
        () => completions(component.uri, eventCompletionPosition),
        (result) => labels(result).includes('search:completed'),
        'Live component event completion',
    );
    assert.ok(labels(eventItems).includes('search:completed'));
    const eventPosition = positionInside(component, "emit('search:completed')", 'search:completed');
    const eventDefinitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', component.uri, eventPosition);
    assert.ok(locationPaths(eventDefinitions).includes(component.uri.path));
    const eventReferences = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', component.uri, eventPosition);
    assert.ok(locationPaths(eventReferences).filter((item) => item === component.uri.path).length >= 2);
}
