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

export const eventTests: TestCase[] = [
    ['Events complete event names', testCompletion],
    ['Events show listener hover metadata', testHover],
    ['Events navigate between dispatches, events and listeners', testNavigation],
    ['Events provide event and listener code lenses', testCodeLenses],
    ['Events diagnose invalid listener methods', testDiagnostics],
];

async function testCompletion(): Promise<void> {
    const contents = `<?php

namespace App\\LspE2e;

use Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener;

#[AsEventListener(event: 'App\\Event\\Ord')]
final class EventCompletion
{
}
`;

    await withTemporaryDocument('EventCompletion.php', contents, async (document) => {
        const position = positionAfter(document, "event: 'App\\Event\\Ord");
        const items = await waitFor(
            () => completions(document.uri, position),
            (results) => labels(results).includes('App\\Event\\OrderPlaced'),
            'event name completion',
        );
        assert.ok(labels(items).includes('App\\Event\\OrderPlaced'));
    });
}

async function testHover(): Promise<void> {
    const document = await open('src/Event/OrderPlacedDispatcher.php');
    const position = positionInside(document, 'dispatch(new OrderPlaced())', 'OrderPlaced');
    const hovers = await waitFor(
        () => vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position),
        (items) => hoverText(items).includes('Symfony event'),
        'event hover',
    );

    assert.ok(hoverText(hovers).includes('NotifyCustomer::onOrderPlaced'));
}

async function testNavigation(): Promise<void> {
    const dispatcher = await open('src/Event/OrderPlacedDispatcher.php');
    const position = positionInside(dispatcher, 'dispatch(new OrderPlaced())', 'OrderPlaced');
    const definitions = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', dispatcher.uri, position),
        (items) => locationPaths(items).some((item) => item.endsWith('/src/Event/OrderPlaced.php'))
            && locationPaths(items).some((item) => item.endsWith('/src/EventListener/NotifyCustomer.php')),
        'event definitions',
    );
    assert.deepEqual(
        locationPaths(definitions).filter((item) => item.endsWith('/OrderPlaced.php') || item.endsWith('/NotifyCustomer.php')).sort(),
        [
            vscode.Uri.joinPath(workspace().uri, 'src/Event/OrderPlaced.php').path,
            vscode.Uri.joinPath(workspace().uri, 'src/EventListener/NotifyCustomer.php').path,
        ].sort(),
    );

    const event = await open('src/Event/OrderPlaced.php');
    const eventPosition = positionInside(event, 'class OrderPlaced', 'OrderPlaced');
    const references = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', event.uri, eventPosition),
        (items) => locationPaths(items).some((item) => item.endsWith('/src/Event/OrderPlacedDispatcher.php')),
        'event dispatch references',
    );
    assert.ok(locationPaths(references).some((item) => item.endsWith('/src/Event/OrderPlacedDispatcher.php')));
}

async function testCodeLenses(): Promise<void> {
    const event = await open('src/Event/OrderPlaced.php');
    const eventLenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', event.uri),
        (items) => items.some((item) => item.command?.title.includes('event listener')),
        'event code lens',
    );
    assert.ok(eventLenses.some((item) => '1 event listener' === item.command?.title));

    const listener = await open('src/EventListener/NotifyCustomer.php');
    const listenerLenses = await waitFor(
        () => vscode.commands.executeCommand<vscode.CodeLens[]>('vscode.executeCodeLensProvider', listener.uri),
        (items) => items.some((item) => item.command?.title.includes('Listens to')),
        'event listener code lens',
    );
    assert.ok(listenerLenses.some((item) => 'Listens to 1 event' === item.command?.title));
}

async function testDiagnostics(): Promise<void> {
    const contents = `<?php

namespace App\\LspE2e;

use Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener;

#[AsEventListener(event: 'App\\Event\\OrderPlaced', method: 'missing')]
final class InvalidEventListener
{
}
`;

    await withTemporaryDocument('InvalidEventListener.php', contents, async (document) => {
        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'event.invalid_listener_method' === item.code),
            'invalid event listener diagnostic',
        );
        assert.ok(diagnostics.some((item) => item.message.includes('InvalidEventListener::missing')));
    });
}
