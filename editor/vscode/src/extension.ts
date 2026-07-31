import * as fs from 'node:fs';
import * as path from 'node:path';
import * as vscode from 'vscode';
import {
    LanguageClient,
    LanguageClientOptions,
    ServerOptions,
} from 'vscode-languageclient/node';

let client: LanguageClient | undefined;

export async function activate(context: vscode.ExtensionContext): Promise<void> {
    const configuration = vscode.workspace.getConfiguration('symfonyLsp');
    const configuredServerPath = configuration.get<string>('serverPath', '').trim();
    const serverPath = configuredServerPath || findServerPath(context.extensionPath);

    if (!serverPath || !fs.existsSync(serverPath)) {
        void vscode.window.showErrorMessage(
            'Symfony LSP executable not found. Configure symfonyLsp.serverPath with an absolute path to bin/symfony-lsp.',
        );

        return;
    }

    const serverOptions: ServerOptions = {
        command: serverPath,
        options: {
            cwd: workspaceDirectory(),
        },
    };
    const outputChannel = vscode.window.createOutputChannel('Symfony LSP', { log: true });
    context.subscriptions.push(outputChannel);
    const clientOptions: LanguageClientOptions = {
        documentSelector: [
            { scheme: 'file', language: 'php' },
            { scheme: 'file', language: 'twig' },
            { scheme: 'file', language: 'yaml' },
            { scheme: 'file', language: 'json' },
            { scheme: 'file', language: 'xml' },
        ],
        outputChannel,
        traceOutputChannel: outputChannel,
        initializationOptions: {
            workspaceTrust: configuration.get<boolean>('trustWorkspace', false),
            phpCommand: configuration.get<string[]>('phpCommand', ['php']),
            environment: configuration.get<string>('environment', 'dev'),
            debug: configuration.get<boolean>('debug', true),
        },
        synchronize: {
            configurationSection: 'symfonyLsp',
            fileEvents: vscode.workspace.createFileSystemWatcher('**/composer.{json,lock}'),
        },
    };

    client = new LanguageClient(
        'symfonyLsp',
        'Symfony LSP',
        serverOptions,
        clientOptions,
    );
    context.subscriptions.push(client);
    await client.start();
}

export async function deactivate(): Promise<void> {
    if (client) {
        await client.stop();
    }
}

function findServerPath(extensionPath: string): string | undefined {
    const candidates = [
        path.resolve(extensionPath, '..', '..', 'bin', 'symfony-lsp'),
        path.resolve(extensionPath, '..', '..', '..', 'bin', 'symfony-lsp'),
    ];

    return candidates.find(fs.existsSync);
}

function workspaceDirectory(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}
