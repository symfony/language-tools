import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    hoverText,
    labels,
    positionAfter,
    positionInside,
    TestCase,
    waitFor,
    withTemporaryDocument,
} from './support';

export const configurationTests: TestCase[] = [
    ['Bundle configuration completes, describes, links and validates keys', testConfigurationFeatures],
];

async function testConfigurationFeatures(): Promise<void> {
    const contents = `imports:
    - { resource: ../config/services.yaml }
framework:
    rout
`;
    await withTemporaryDocument('configuration.yaml', contents, async (document) => {
        const items = await waitFor(
            () => completions(document.uri, positionAfter(document, '    rout')),
            (result) => labels(result).includes('router'),
            'bundle configuration completion',
        );
        assert.ok(labels(items).includes('router'));

        const links = await vscode.commands.executeCommand<vscode.DocumentLink[]>('vscode.executeLinkProvider', document.uri);
        assert.ok(links.some((item) => item.target?.path.endsWith('/config/services.yaml')));

        const editor = vscode.window.activeTextEditor;
        assert.equal(editor?.document.uri.toString(), document.uri.toString());
        const invalid = `framework:
    router:
        utf8: invalid
    unknown_key: true
`;
        await editor.edit((builder) => {
            builder.replace(new vscode.Range(document.positionAt(0), document.positionAt(document.getText().length)), invalid);
        });

        const position = positionInside(document, 'utf8: invalid', 'utf8');
        const hovers = await waitFor(
            () => vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position),
            (result) => hoverText(result).includes('framework.router.utf8'),
            'bundle configuration hover',
        );
        assert.match(hoverText(hovers), /Type: `boolean`/);

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (result) => result.some((item) => 'config.invalid_type' === item.code)
                && result.some((item) => 'config.unknown_key' === item.code),
            'bundle configuration diagnostics',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('unknown_key')));
    });
}
