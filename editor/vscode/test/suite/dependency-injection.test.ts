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

export const dependencyInjectionTests: TestCase[] = [
    ['Dependency injection completes, describes, navigates and validates symbols', testDependencyInjectionFeatures],
];

async function testDependencyInjectionFeatures(): Promise<void> {
    const contents = `services:
    app.e2e_consumer:
        arguments:
            - '@app.fixture_contr'
            - '@app.fixture_controller'
            - '%app.fixture_na'
            - '%app.fixture_name%'
            - '@missing.service'
            - '%missing.parameter%'
`;
    await withTemporaryDocument('services.yaml', contents, async (document) => {
        const serviceItems = await waitFor(
            () => completions(document.uri, positionAfter(document, '@app.fixture_contr')),
            (items) => labels(items).includes('app.fixture_controller'),
            'service completion',
        );
        assert.ok(labels(serviceItems).includes('app.fixture_controller'));

        const parameterItems = await completions(document.uri, positionAfter(document, '%app.fixture_na'));
        assert.ok(labels(parameterItems).includes('app.fixture_name'));

        const position = positionInside(document, "'@app.fixture_controller'", 'app.fixture_controller');
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position);
        assert.match(hoverText(hovers), /Service: `app\.fixture_controller`/);
        assert.match(hoverText(hovers), /Class: `App\\Controller\\HomeController`/);

        const definitions = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', document.uri, position);
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/config/services.yaml')));
        assert.ok(locationPaths(definitions).some((item) => item.endsWith('/src/Controller/HomeController.php')));

        const references = await vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', document.uri, position);
        assert.ok(locationPaths(references).includes(document.uri.path));

        const edit = await vscode.commands.executeCommand<vscode.WorkspaceEdit>('vscode.executeDocumentRenameProvider', document.uri, position, 'app.fixture_home_controller');
        assert.ok(edit.entries().some(([uri]) => uri.path.endsWith('/config/services.yaml')));
        assert.ok(edit.entries().some(([uri]) => uri.path === document.uri.path));

        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'service.not_found' === item.code)
                && items.some((item) => 'parameter.not_found' === item.code),
            'dependency injection diagnostics',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('missing.service')));
        assert.ok(diagnostics.some((item) => item.message.includes('missing.parameter')));
    });
}
