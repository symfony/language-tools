import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import { assetTests } from './assets.test';
import { configurationTests } from './configuration.test';
import { dependencyInjectionTests } from './dependency-injection.test';
import { environmentTests } from './environment.test';
import { eventTests } from './events.test';
import { lifecycleTests } from './lifecycle.test';
import { messengerTests } from './messenger.test';
import { routingTests } from './routing.test';
import { securityTests } from './security.test';
import { stimulusTests } from './stimulus.test';
import { cleanupTemporaryDocuments, setWorkspace, TestCase } from './support';
import { translationTests } from './translation.test';
import { twigTests } from './twig.test';

export async function run(): Promise<void> {
    const workspace = vscode.workspace.workspaceFolders?.[0];
    assert.ok(workspace, 'The RuntimeApplication workspace was not opened.');
    setWorkspace(workspace);

    const extension = vscode.extensions.all.find((candidate) => 'symfony-lsp' === candidate.packageJSON.name);
    assert.ok(extension, 'The Symfony LSP development extension was not found.');
    await extension.activate();

    const tests: TestCase[] = [
        ...lifecycleTests,
        ...routingTests,
        ...dependencyInjectionTests,
        ...twigTests,
        ...assetTests,
        ...stimulusTests,
        ...translationTests,
        ...environmentTests,
        ...configurationTests,
        ...messengerTests,
        ...eventTests,
        ...securityTests,
    ];
    try {
        for (const [name, test] of tests) {
            await test();
            console.log(`✓ ${name}`);
        }
    } finally {
        await cleanupTemporaryDocuments();
    }
}
