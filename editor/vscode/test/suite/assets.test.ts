import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    hoverText,
    labels,
    locationPaths,
    positionAfter,
    positionInside,
    TestCase,
    waitFor,
    withTemporaryDocument,
} from './support';

export const assetTests: TestCase[] = [
    ['Assets and importmaps complete, navigate, link and diagnose references', testAssetLanguageFeatures],
];

async function testAssetLanguageFeatures(): Promise<void> {
    const contents = `{{ asset('app.j') }}
{{ asset('app.js') }}
{{ importmap('ap') }}
{{ importmap('app') }}
{{ importmap('missing') }}
`;
    await withTemporaryDocument('assets.html.twig', contents, async (document) => {
        const assetCompletionPosition = positionAfter(document, "asset('app.j");
        const assetItems = await waitFor(
            () => completions(document.uri, assetCompletionPosition),
            (result) => labels(result).includes('app.js'),
            'asset completion',
        );
        assert.ok(labels(assetItems).includes('app.js'));

        const assetPosition = positionInside(document, "asset('app.js')", 'app.js');
        const assetHovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, assetPosition);
        assert.match(hoverText(assetHovers), /AssetMapper asset: `app\.js`/);
        const assetDefinitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, assetPosition);
        assert.ok(locationPaths(assetDefinitions).some((item) => item.endsWith('/assets/app.js')));

        const entryCompletionPosition = positionAfter(document, "importmap('ap");
        const entryItems = await waitFor(
            () => completions(document.uri, entryCompletionPosition),
            (result) => labels(result).includes('app'),
            'importmap completion',
        );
        assert.ok(labels(entryItems).includes('app'));

        const entryPosition = positionInside(document, "importmap('app')", 'app');
        const entryDefinitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, entryPosition);
        assert.ok(locationPaths(entryDefinitions).some((item) => item.endsWith('/importmap.php')));
        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, entryPosition);
        assert.ok(locationPaths(references).includes(document.uri.path));

        const links = await vscode.commands.executeCommand<vscode.DocumentLink[]>('vscode.executeLinkProvider', document.uri);
        assert.ok(links.some((item) => item.target?.path.endsWith('/assets/app.js')));
        assert.ok(links.some((item) => item.target?.path.endsWith('/importmap.php')));

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (result) => result.some((item) => 'importmap.unknown_entrypoint' === item.code),
            'unknown importmap entrypoint diagnostic',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('missing')));
    }, 'twig');
}
