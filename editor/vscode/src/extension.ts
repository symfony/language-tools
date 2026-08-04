import * as fs from 'node:fs';
import * as path from 'node:path';
import * as vscode from 'vscode';
import {
    LanguageClient,
    LanguageClientOptions,
    ServerOptions,
} from 'vscode-languageclient/node';
import { IndexStatusController } from './indexStatus';

let client: LanguageClient | undefined;

export async function activate(context: vscode.ExtensionContext): Promise<void> {
    const configuration = vscode.workspace.getConfiguration('symfonyLsp', vscode.workspace.workspaceFolders?.[0]?.uri ?? null);
    const configuredServerPath = configuration.get<string>('serverPath', '').trim();
    const serverPath = configuredServerPath || findServerPath(context.extensionPath);

    if (!serverPath || !fs.existsSync(serverPath)) {
        void vscode.window.showErrorMessage(
            'Symfony LSP executable not found. Install the extension package for this platform or configure symfonyLsp.serverPath.',
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
            { scheme: 'file', language: 'javascript' },
            { scheme: 'file', language: 'typescript' },
            { scheme: 'file', language: 'twig' },
            { scheme: 'file', language: 'html', pattern: '**/*.twig' },
            { scheme: 'file', language: 'yaml' },
            { scheme: 'file', language: 'json' },
            { scheme: 'file', language: 'xml' },
            { scheme: 'file', pattern: '**/.env*' },
        ],
        outputChannel,
        traceOutputChannel: outputChannel,
        initializationOptions: {
            workspaceTrust: vscode.workspace.isTrusted,
            phpCommand: configuration.get<string[]>('phpCommand', ['php']),
            consolePath: configuration.get<string>('consolePath', 'bin/console'),
            environment: configuration.get<string>('environment', 'dev'),
            debug: configuration.get<boolean>('debug', true),
            runtimeIndexing: configuration.get<boolean>('runtimeIndexing', true),
            projectRoots: configuration.get<string[]>('projectRoots', []),
            trace: configuration.get<string>('trace', 'off'),
        },
        synchronize: {
            configurationSection: 'symfonyLsp',
            fileEvents: [
                vscode.workspace.createFileSystemWatcher('**/*.{php,twig,yaml,yml,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}'),
                vscode.workspace.createFileSystemWatcher('**/.env*'),
                vscode.workspace.createFileSystemWatcher('**/composer.{json,lock}'),
            ],
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

    const statusBar = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 100);
    const statusController = new IndexStatusController(client, statusBar, outputChannel);
    context.subscriptions.push(statusController);
    statusController.start(context);
}

export async function deactivate(): Promise<void> {
    if (client) {
        await client.stop();
    }
}

function findServerPath(extensionPath: string): string | undefined {
    const executable = 'win32' === process.platform ? 'symfony-lsp.exe' : 'symfony-lsp';
    const candidates = [
        path.resolve(extensionPath, 'server', executable),
        path.resolve(extensionPath, '..', '..', 'bin', 'symfony-lsp'),
        path.resolve(extensionPath, '..', '..', '..', 'bin', 'symfony-lsp'),
    ];

    return candidates.find(fs.existsSync);
}

function workspaceDirectory(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}
