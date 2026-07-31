import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';
import { configurationTests } from './configuration.test';
import { dependencyInjectionTests } from './dependency-injection.test';
import { environmentTests } from './environment.test';
import { eventTests } from './events.test';
import { lifecycleTests } from './lifecycle.test';
import { messengerTests } from './messenger.test';
import { routingTests } from './routing.test';
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
        ...translationTests,
        ...environmentTests,
        ...configurationTests,
        ...messengerTests,
        ...eventTests,
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
