import * as assert from 'node:assert/strict';
import * as path from 'node:path';
import * as vscode from 'vscode';
import { State } from 'vscode-languageclient/node';
import {
    configuredAnalysisOptions,
    serverEnvironment,
    serverStartupMessage,
    useSocketTransport,
} from '../../src/extension';
import { indexStatusPollingEnabled } from '../../src/indexStatus';
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
    environment: string;
    runtimeEnabled: boolean;
    trusted: boolean;
    source: { state: string };
    runtime: { state: string };
}

export const lifecycleTests: TestCase[] = [
    ['Server environment carries the configured memory limit', testServerEnvironment],
    ['Only explicit analysis settings are forwarded at startup', testConfiguredAnalysisOptions],
    ['Index status polling follows the language client state', testIndexStatusPolling],
    ['Server reports and refreshes indexes', testIndexCommands],
    ['Server remains responsive after workspace configuration changes', testConfigurationChange],
];

async function testServerEnvironment(): Promise<void> {
    const serverName = 'win32' === process.platform ? 'symfony-lsp.exe' : 'symfony-lsp';
    const serverPath = path.join(workspace().uri.fsPath, serverName);
    assert.equal(serverEnvironment(), undefined);
    assert.equal(serverEnvironment('512M')?.SYMFONY_LSP_MEMORY_LIMIT, '512M');
    assert.equal(useSocketTransport(), 'win32' === process.platform);
    assert.equal(
        serverStartupMessage('1.2.3', serverPath, 'bundled', 'stdio'),
        `Symfony Language Tools extension 1.2.3 starting on ${process.platform}-${process.arch}; server (bundled): ${serverPath}; transport: stdio.`,
    );
}

async function testConfiguredAnalysisOptions(): Promise<void> {
    const configuration = {
        inspect: (name: string): { workspaceValue: unknown } | undefined => 'environment' === name
            ? { workspaceValue: 'test' }
            : undefined,
    } as vscode.WorkspaceConfiguration;

    assert.deepEqual(configuredAnalysisOptions(configuration), { environment: 'test' });
}

async function testIndexStatusPolling(): Promise<void> {
    assert.equal(indexStatusPollingEnabled(State.Stopped), false);
    assert.equal(indexStatusPollingEnabled(State.Starting), false);
    assert.equal(indexStatusPollingEnabled(State.StartFailed), false);
    assert.equal(indexStatusPollingEnabled(State.Running), true);
}

async function testIndexCommands(): Promise<void> {
    const commands = await vscode.commands.getCommands(true);
    assert.ok(commands.includes('symfonyLsp.refreshIndex'));
    assert.ok(commands.includes('symfonyLsp.indexStatus'));
    assert.ok(commands.includes('symfonyLsp.switchEnvironment'));

    const statuses = await waitFor(
        () => vscode.commands.executeCommand<IndexStatus[]>('symfonyLsp.indexStatus'),
        (items) => 1 === items?.length && 'ready' === items[0].source.state && 'ready' === items[0].runtime.state,
        'ready source and runtime indexes',
    );
    assert.equal(statuses[0].root, workspace().uri.fsPath);
    assert.equal(statuses[0].environment, 'test');
    assert.equal(statuses[0].runtimeEnabled, true);
    assert.equal(statuses[0].trusted, true);

    const refreshed = await vscode.commands.executeCommand<IndexStatus[]>('symfonyLsp.refreshIndex');
    assert.equal(refreshed[0].source.state, 'ready');
    assert.equal(refreshed[0].runtime.state, 'ready');

    const switched = await vscode.commands.executeCommand<IndexStatus[]>('symfonyLsp.switchEnvironment', 'test', statuses[0].root);
    assert.equal(switched[0].environment, 'test');
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
