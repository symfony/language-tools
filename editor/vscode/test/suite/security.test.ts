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
} from './support';

export const securityTests: TestCase[] = [
    ['Security completes roles and providers', testCompletion],
    ['Security shows role hierarchy and voter metadata', testHover],
    ['Security navigates and finds role references', testNavigation],
    ['Security publishes unknown provider diagnostics', testDiagnostics],
];

async function testCompletion(): Promise<void> {
    const contents = `<?php

namespace App\\LspE2e;

use Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;

#[IsGranted('ROLE_A')]
final class SecurityCompletion
{
}
`;
    await withTemporaryDocument('SecurityCompletion.php', contents, async (document) => {
        const position = positionAfter(document, "IsGranted('ROLE_A");
        const items = await waitFor(
            () => completions(document.uri, position),
            (results) => labels(results).includes('ROLE_ADMIN'),
            'security role completion',
        );
        assert.ok(labels(items).includes('ROLE_ADMIN'));
    });

    const yaml = `security:
    firewalls:
        completion:
            provider: fixture_
`;
    await withTemporaryDocument('security-completion.yaml', yaml, async (document) => {
        const position = positionAfter(document, 'provider: fixture_');
        const items = await waitFor(
            () => completions(document.uri, position),
            (results) => labels(results).includes('fixture_users'),
            'security provider completion',
        );
        assert.ok(labels(items).includes('fixture_users'));
    });
}

async function testHover(): Promise<void> {
    const document = await open('src/Controller/HomeController.php');
    const position = positionInside(document, "IsGranted('ROLE_ADMIN')", 'ROLE_ADMIN');
    const hovers = await waitFor(
        () => vscode.commands.executeCommand<vscode.Hover[]>('vscode.executeHoverProvider', document.uri, position),
        (items) => hoverText(items).includes('Security role'),
        'security role hover',
    );

    assert.ok(hoverText(hovers).includes('ROLE_USER'));
    assert.ok(hoverText(hovers).includes('App\\Security\\PostVoter'));
}

async function testNavigation(): Promise<void> {
    const config = await open('config/packages/security.yaml');
    const providerPosition = positionInside(config, 'provider: fixture_users', 'fixture_users');
    const definitions = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeDefinitionProvider', config.uri, providerPosition),
        (items) => locationPaths(items).some((item) => item.endsWith('/config/packages/security.yaml')),
        'security provider definition',
    );
    assert.ok(locationPaths(definitions).some((item) => item.endsWith('/config/packages/security.yaml')));

    const controller = await open('src/Controller/HomeController.php');
    const rolePosition = positionInside(controller, "IsGranted('ROLE_ADMIN')", 'ROLE_ADMIN');
    const references = await waitFor(
        () => vscode.commands.executeCommand<Array<vscode.Location | vscode.LocationLink>>('vscode.executeReferenceProvider', controller.uri, rolePosition),
        (items) => locationPaths(items).some((item) => item.endsWith('/templates/fixture.html.twig')),
        'security role references',
    );
    assert.ok(locationPaths(references).some((item) => item.endsWith('/templates/fixture.html.twig')));
}

async function testDiagnostics(): Promise<void> {
    const contents = `security:
    providers:
        users:
            memory: ~
    firewalls:
        main:
            provider: missing_provider
`;
    await withTemporaryDocument('invalid-security.yaml', contents, async (document) => {
        const diagnostics = await waitFor(
            async () => vscode.languages.getDiagnostics(document.uri),
            (items) => items.some((item) => 'security.unknown_provider' === item.code),
            'unknown security provider diagnostic',
        );
        assert.ok(diagnostics.some((item) => 'Unknown security provider "missing_provider".' === item.message));
    });
}
