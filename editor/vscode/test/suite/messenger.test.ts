import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import {
    completions,
    hoverText,
    labels,
    locationPaths,
    open,
    positionAfter,
    positionInside,
    TestCase,
    waitFor,
    withTemporaryDocument,
    workspace,
} from './support';

export const messengerTests: TestCase[] = [
    ['Messenger completes bus and transport names', testCompletion],
    ['Messenger shows transport hover metadata', testHover],
    ['Messenger navigates between dispatches, messages and handlers', testNavigation],
    ['Messenger provides message and handler code lenses', testCodeLenses],
    ['Messenger publishes unknown bus diagnostics', testDiagnostics],
];

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
            vscode.Uri.joinPath(workspace().uri, 'src/Message/Ping.php').path,
            vscode.Uri.joinPath(workspace().uri, 'src/MessageHandler/PingHandler.php').path,
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
    const contents = `<?php

namespace App\\LspE2e;

use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;

#[AsMessageHandler(bus: 'missing.bus')]
final class UnknownBus
{
}
`;

    await withTemporaryDocument('UnknownBus.php', contents, async (document) => {
        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'messenger.unknown_bus' === item.code),
            'unknown Messenger bus diagnostic',
        );
        assert.ok(diagnostics.some((item) => 'Unknown Messenger bus "missing.bus".' === item.message));
    });
}
