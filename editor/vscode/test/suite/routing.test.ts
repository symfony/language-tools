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

export const routingTests: TestCase[] = [
    ['Routing completes, describes and navigates route names', testRouteLanguageFeatures],
    ['Routing links route references and updates diagnostics for unsaved edits', testRouteLinksAndOverlays],
];

async function testRouteLanguageFeatures(): Promise<void> {
    const contents = `<?php

final class RouteConsumer extends AbstractController
{
    public function test(): void
    {
        $this->generateUrl('fixture_');
        $this->generateUrl('fixture_home');
    }
}
`;
    await withTemporaryDocument('RouteConsumer.php', contents, async (document) => {
        const completionPosition = positionAfter(document, "generateUrl('fixture_");
        const items = await waitFor(
            () => completions(document.uri, completionPosition),
            (result) => labels(result).includes('fixture_home'),
            'route completion',
        );
        assert.ok(labels(items).includes('fixture_home'));

        const position = positionInside(document, "generateUrl('fixture_home')", 'fixture_home');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position);
        assert.match(hoverText(hovers), /Path: `\/fixture`/);

        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, position);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/config/routes.yaml')));

        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, position);
        assert.ok(locationPaths(references).includes(document.uri.path));

        const edit = await vscode.commands.executeCommand<vscode.WorkspaceEdit>('vscode.executeDocumentRenameProvider', document.uri, position, 'fixture_welcome');
        assert.ok(edit.entries().some(([uri]) => uri.path.endsWith('/config/routes.yaml')));
        assert.ok(edit.entries().some(([uri]) => uri.path === document.uri.path));
    });
}

async function testRouteLinksAndOverlays(): Promise<void> {
    await withTemporaryDocument('route.html.twig', "{{ path('fixture_home') }}\n{{ path('missing_route') }}\n", async (document) => {
        const links = await waitFor(
            () => vscode.commands.executeCommand<vscode.DocumentLink[]>('vscode.executeLinkProvider', document.uri),
            (items) => items.some((item) => item.target?.path.endsWith('/config/routes.yaml')),
            'route document link',
        );
        assert.ok(links.some((item) => item.tooltip?.includes('fixture_home')));

        await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'route.not_found' === item.code),
            'missing route diagnostic',
        );

        const editor = vscode.window.activeTextEditor;
        assert.equal(editor?.document.uri.toString(), document.uri.toString());
        await editor.edit((builder) => {
            builder.replace(new vscode.Range(document.positionAt(0), document.positionAt(document.getText().length)), "{{ path('fixture_home') }}\n");
        });
        await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => !items.some((item) => 'route.not_found' === item.code),
            'cleared route diagnostic after an unsaved edit',
        );
    }, 'twig');
}
