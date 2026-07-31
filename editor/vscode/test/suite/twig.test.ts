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

export const twigTests: TestCase[] = [
    ['Twig templates complete, navigate, link and diagnose references', testTemplateLanguageFeatures],
];

async function testTemplateLanguageFeatures(): Promise<void> {
    const contents = `<?php

final class TemplateConsumer
{
    public function test(): void
    {
        $this->render('fixture.html.t');
        $this->render('fixture.html.twig');
        $this->render('missing.html.twig');
    }
}
`;
    await withTemporaryDocument('TemplateConsumer.php', contents, async (document) => {
        const completionPosition = positionAfter(document, "render('fixture.html.t");
        const items = await waitFor(
            () => completions(document.uri, completionPosition),
            (result) => labels(result).includes('fixture.html.twig'),
            'template completion',
        );
        assert.ok(labels(items).includes('fixture.html.twig'));

        const position = positionInside(document, "render('fixture.html.twig')", 'fixture.html.twig');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position);
        assert.match(hoverText(hovers), /Template: `fixture\.html\.twig`/);

        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, position);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/templates/fixture.html.twig')));

        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, position);
        assert.ok(locationPaths(references).includes(document.uri.path));

        const links = await vscode.commands.executeCommand<vscode.DocumentLink[]>('vscode.executeLinkProvider', document.uri);
        assert.ok(links.some((item) => item.target?.path.endsWith('/templates/fixture.html.twig')));

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (result) => result.some((item) => 'template.not_found' === item.code),
            'missing template diagnostic',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('missing.html.twig')));
    });
}
