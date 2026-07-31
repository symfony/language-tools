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
    workspace,
} from './support';

export const translationTests: TestCase[] = [
    ['Translations complete, describe, navigate, rename and diagnose keys', testTranslationFeatures],
];

async function testTranslationFeatures(): Promise<void> {
    const configuration = vscode.workspace.getConfiguration('symfonyLsp', workspace().uri);
    await configuration.update('translationDiagnostics', true, vscode.ConfigurationTarget.Workspace);
    const contents = `<?php

$translator->trans('fixture.mes');
$translator->trans('fixture.message');
$translator->trans('missing.key');
`;
    try {
        await withTemporaryDocument('TranslationConsumer.php', contents, async (document) => {
            const items = await waitFor(
                () => completions(document.uri, positionAfter(document, "trans('fixture.mes")),
                (result) => labels(result).includes('fixture.message'),
                'translation completion',
            );
            assert.ok(labels(items).includes('fixture.message'));

            const position = positionInside(document, "trans('fixture.message')", 'fixture.message');
            const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position);
            assert.match(hoverText(hovers), /Fixture translation/);

            const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, position);
            assert.ok(locationPaths(definitions).some((item) => item.endsWith('/translations/messages.en.yaml')));

            const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, position);
            assert.ok(locationPaths(references).includes(document.uri.path));
            assert.ok(locationPaths(references).some((item) => item.endsWith('/templates/fixture.html.twig')));

            const edit = await vscode.commands.executeCommand<vscode.WorkspaceEdit>('vscode.executeDocumentRenameProvider', document.uri, position, 'fixture.welcome');
            assert.ok(edit.entries().some(([uri]) => uri.path.endsWith('/translations/messages.en.yaml')));
            assert.ok(edit.entries().some(([uri]) => uri.path.endsWith('/templates/fixture.html.twig')));

            const diagnostics = await waitFor(
                async () => vscode.languages.getDiagnostics(document.uri),
                (result) => result.some((item) => 'translation.not_found' === item.code),
                'missing translation diagnostic',
            );
            assert.ok(diagnostics.some((item) => item.message.includes('missing.key')));
        });
    } finally {
        await configuration.update('translationDiagnostics', false, vscode.ConfigurationTarget.Workspace);
    }
}
