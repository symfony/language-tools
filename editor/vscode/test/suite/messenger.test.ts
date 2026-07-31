import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';

let workspace: vscode.WorkspaceFolder;

export async function run(): Promise<void> {
    workspace = vscode.workspace.workspaceFolders?.[0] as vscode.WorkspaceFolder;
    assert.ok(workspace, 'The RuntimeApplication workspace was not opened.');

    const extension = vscode.extensions.all.find((candidate) => 'symfony-lsp' === candidate.packageJSON.name);
    assert.ok(extension, 'The Symfony LSP development extension was not found.');
    await extension.activate();

    const tests: Array<[string, () => Promise<void>]> = [
        ['completes bus and transport names', testCompletion],
        ['shows transport hover metadata', testHover],
        ['navigates between dispatches, messages and handlers', testNavigation],
        ['provides message and handler code lenses', testCodeLenses],
        ['publishes unknown bus diagnostics', testDiagnostics],
    ];
    for (const [name, test] of tests) {
        await test();
        console.log(`✓ ${name}`);
    }
}

async function testCompletion(): Promise<void> {
    const document = await open('config/packages/framework.yaml');
    const busPosition = positionAfter(document, 'default_bus: command.');
    const busCompletions = await waitFor(
        () => completions(document.uri, busPosition),
        (items) => labels(items).includes('command.bus'),
        'command.bus completion',
    );
    assert.ok(labels(busCompletions).includes('command.bus'));

    const transportPosition = positionAfter(document, 'App\\Message\\Ping: asy');
    const transportCompletions = await waitFor(
        () => completions(document.uri, transportPosition),
        (items) => labels(items).includes('async'),
        'async transport completion',
    );
    assert.ok(labels(transportCompletions).includes('async'));
}

async function testHover(): Promise<void> {
    const document = await open('config/packages/framework.yaml');
    const position = positionInside(document, 'App\\Message\\Ping: async', 'async');
    const hovers = await waitFor(
        () => vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position),
        (items) => hoverText(items).includes('Messenger transport'),
        'Messenger transport hover',
    );

    assert.ok(hoverText(hovers).includes('Routed messages'));
}

async function testNavigation(): Promise<void> {
    const controller = await open('src/Controller/PingController.php');
    const dispatchPosition = positionInside(controller, 'dispatch(new Ping())', 'Ping');
    const definitions = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', controller.uri, dispatchPosition),
        (items) => locationPaths(items).some((item) => item.endsWith('/src/Message/Ping.php'))
            && locationPaths(items).some((item) => item.endsWith('/src/MessageHandler/PingHandler.php')),
        'dispatch definitions',
    );
    assert.deepEqual(
        locationPaths(definitions).filter((item) => item.endsWith('/Ping.php') || item.endsWith('/PingHandler.php')).sort(),
        [
            vscode.Uri.joinPath(workspace.uri, 'src/Message/Ping.php').path,
            vscode.Uri.joinPath(workspace.uri, 'src/MessageHandler/PingHandler.php').path,
        ].sort(),
    );

    const message = await open('src/Message/Ping.php');
    const messagePosition = positionInside(message, 'class Ping', 'Ping');
    const references = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', message.uri, messagePosition),
        (items) => locationPaths(items).some((item) => item.endsWith('/src/Controller/PingController.php')),
        'message dispatch references',
    );
    assert.ok(locationPaths(references).some((item) => item.endsWith('/src/Controller/PingController.php')));
}

async function testCodeLenses(): Promise<void> {
    const message = await open('src/Message/Ping.php');
    const messageLenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', message.uri),
        (items) => items.some((item) => item.command?.title.includes('Messenger handler')),
        'message code lens',
    );
    assert.ok(messageLenses.some((item) => '1 Messenger handler' === item.command?.title));

    const handler = await open('src/MessageHandler/PingHandler.php');
    const handlerLenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', handler.uri),
        (items) => items.some((item) => item.command?.title.includes('Messenger message')),
        'handler code lens',
    );
    assert.ok(handlerLenses.some((item) => 'Handles 1 Messenger message' === item.command?.title));
}

async function testDiagnostics(): Promise<void> {
    const directory = vscode.Uri.joinPath(workspace.uri, '.lsp-e2e');
    const uri = vscode.Uri.joinPath(directory, 'UnknownBus.php');
    const contents = `<?php

namespace App\\LspE2e;

use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;

#[AsMessageHandler(bus: 'missing.bus')]
final class UnknownBus
{
}
`;

    await vscode.workspace.fs.createDirectory(directory);
    await vscode.workspace.fs.writeFile(uri, Buffer.from(contents));
    try {
        const document = await vscode.workspace.openTextDocument(uri);
        await vscode.window.showTextDocument(document);
        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(uri),
            (items) => items.some((item) => 'messenger.unknown_bus' === item.code),
            'unknown Messenger bus diagnostic',
        );
        assert.ok(diagnostics.some((item) => 'Unknown Messenger bus "missing.bus".' === item.message));
    } finally {
        await vscode.commands.executeCommand('workbench.action.closeActiveEditor');
        await vscode.workspace.fs.delete(directory, { recursive: true, useTrash: false });
    }
}

async function open(relativePath: string): Promise<vscode.TextDocument> {
    const document = await vscode.workspace.openTextDocument(vscode.Uri.joinPath(workspace.uri, relativePath));
    await vscode.window.showTextDocument(document, { preview: false });

    return document;
}

async function completions(uri: vscode.Uri, position: vscode.Position): Promise<vscode.CompletionItem[]> {
    const result = await vscode.commands.executeCommand<vscode.CompletionList>('vscode.executeCompletionItemProvider', uri, position);

    return result?.items ?? [];
}

function labels(items: vscode.CompletionItem[]): string[] {
    return items.map((item) => 'string' === typeof item.label ? item.label : item.label.label);
}

function positionAfter(document: vscode.TextDocument, needle: string): vscode.Position {
    const offset = document.getText().indexOf(needle);
    assert.notEqual(offset, -1, `Could not find "${needle}" in ${document.uri.path}.`);

    return document.positionAt(offset + needle.length);
}

function positionInside(document: vscode.TextDocument, lineNeedle: string, symbol: string): vscode.Position {
    const lineOffset = document.getText().indexOf(lineNeedle);
    assert.notEqual(lineOffset, -1, `Could not find "${lineNeedle}" in ${document.uri.path}.`);
    const symbolOffset = lineNeedle.indexOf(symbol);
    assert.notEqual(symbolOffset, -1, `Could not find "${symbol}" in "${lineNeedle}".`);

    return document.positionAt(lineOffset + symbolOffset + Math.floor(symbol.length / 2));
}

function locationPaths(locations: Array<vscode.Location | vscode.LocationLink> | undefined): string[] {
    return (locations ?? []).map((location) => 'uri' in location ? location.uri.path : location.targetUri.path);
}

function hoverText(hovers: vscode.Hover[] | undefined): string {
    return (hovers ?? []).flatMap((hover) => hover.contents).map((contents) => 'string' === typeof contents ? contents : contents.value).join('\n');
}

async function waitFor<T>(probe: () => PromiseLike<T>, ready: (value: T) => boolean, description: string): Promise<T> {
    const deadline = Date.now() + 10_000;
    let lastValue: T | undefined;
    let lastError: unknown;
    while (Date.now() < deadline) {
        try {
            lastValue = await probe();
            if (ready(lastValue)) {
                return lastValue;
            }
        } catch (error: unknown) {
            lastError = error;
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error(`Timed out waiting for ${description}. Last value: ${JSON.stringify(lastValue)}. Last error: ${String(lastError)}`);
}
