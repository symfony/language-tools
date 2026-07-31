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

export const environmentTests: TestCase[] = [
    ['Environment variables and processors complete, navigate and diagnose safely', testEnvironmentFeatures],
];

async function testEnvironmentFeatures(): Promise<void> {
    const contents = `parameters:
    processor: '%env(fixture_up)'
    secret_prefix: '%env(fixture_upper:APP_SEC)'
    secret: '%env(fixture_upper:APP_SECRET)%'
    invalid: '%env(unknown:APP_SECRET)%'
`;
    await withTemporaryDocument('environment.yaml', contents, async (document) => {
        const processorItems = await waitFor(
            () => completions(document.uri, positionAfter(document, '%env(fixture_up')),
            (items) => labels(items).includes('fixture_upper'),
            'environment processor completion',
        );
        assert.ok(labels(processorItems).includes('fixture_upper'));

        const variableItems = await completions(document.uri, positionAfter(document, 'fixture_upper:APP_SEC'));
        assert.ok(labels(variableItems).includes('APP_SECRET'));

        const position = positionInside(document, 'fixture_upper:APP_SECRET', 'APP_SECRET');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position);
        const text = hoverText(hovers);
        assert.match(text, /Environment variable: `APP_SECRET`/);
        assert.match(text, /Expected type: `string`/);
        assert.doesNotMatch(text, /CANARY_SECRET_COMPATIBILITY_VALUE/);

        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, position);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/.env')));

        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, position);
        assert.ok(locationPaths(references).includes(document.uri.path));
        assert.ok(locationPaths(references).some((item) => item.endsWith('/config/packages/framework.yaml')));

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'env.unknown_processor' === item.code),
            'unknown environment processor diagnostic',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('unknown')));
    });
}
