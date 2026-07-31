import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    labels,
    open,
    positionAfter,
    TestCase,
    waitFor,
    workspace,
} from './support';

interface IndexStatus {
    root: string;
    source: { state: string };
    runtime: { state: string };
}

export const lifecycleTests: TestCase[] = [
    ['Server reports and refreshes indexes', testIndexCommands],
    ['Server remains responsive after workspace configuration changes', testConfigurationChange],
];

async function testIndexCommands(): Promise<void> {
    const statuses = await waitFor(
        () => vscode.commands.executeCommand<IndexStatus[]>('symfony.indexStatus'),
        (items) => 1 === items?.length && 'ready' === items[0].source.state && 'ready' === items[0].runtime.state,
        'ready source and runtime indexes',
    );
    assert.equal(statuses[0].root, workspace().uri.fsPath);

    const refreshed = await vscode.commands.executeCommand<IndexStatus[]>('symfony.refreshIndex');
    assert.equal(refreshed[0].source.state, 'ready');
    assert.equal(refreshed[0].runtime.state, 'ready');
}

async function testConfigurationChange(): Promise<void> {
    const configuration = vscode.workspace.getConfiguration('symfonyLsp', workspace().uri);
    await configuration.update('translationDiagnostics', true, vscode.ConfigurationTarget.Workspace);
    try {
        const document = await open('config/packages/framework.yaml');
        const items = await waitFor(
            () => completions(document.uri, positionAfter(document, 'default_bus: command.')),
            (result) => labels(result).includes('command.bus'),
            'completion after a workspace configuration change',
        );
        assert.ok(labels(items).includes('command.bus'));
    } finally {
        await configuration.update('translationDiagnostics', false, vscode.ConfigurationTarget.Workspace);
    }
}
