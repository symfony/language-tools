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

    const sidecarPath = serverSidecarPath(serverPath);
    const serverOptions: ServerOptions = {
        command: serverPath,
        options: {
            cwd: workspaceDirectory(),
            env: serverEnvironment(serverPath),
        },
    };
    const outputChannel = vscode.window.createOutputChannel('Symfony LSP', { log: true });
    context.subscriptions.push(outputChannel);
    const extensionVersion = 'string' === typeof context.extension.packageJSON.version
        ? context.extension.packageJSON.version
        : 'unknown';
    const bundledServerDirectory = path.resolve(context.extensionPath, 'server') + path.sep;
    const serverKind = configuredServerPath
        ? 'configured'
        : serverPath.startsWith(bundledServerDirectory) ? 'bundled' : 'development';
    outputChannel.info(serverStartupMessage(extensionVersion, serverPath, sidecarPath, serverKind));
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
    const serverVersion = client.initializeResult?.serverInfo?.version;
    outputChannel.info(`Symfony LSP server ${'string' === typeof serverVersion ? serverVersion : 'unknown'} initialized.`);

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

export function serverSidecarPath(serverPath: string): string | undefined {
    const executable = 'win32' === process.platform ? 'symfony-lsp-tree-sitter.exe' : 'symfony-lsp-tree-sitter';
    const sidecarPath = path.resolve(path.dirname(serverPath), executable);

    return fs.existsSync(sidecarPath) ? sidecarPath : undefined;
}

export function serverEnvironment(serverPath: string): NodeJS.ProcessEnv | undefined {
    const sidecarPath = serverSidecarPath(serverPath);

    return sidecarPath ? { ...process.env, SYMFONY_LSP_TREE_SITTER: sidecarPath } : undefined;
}

export function serverStartupMessage(
    extensionVersion: string,
    serverPath: string,
    sidecarPath: string | undefined,
    serverKind: 'bundled' | 'configured' | 'development',
): string {
    return [
        `Symfony LSP extension ${extensionVersion} starting on ${process.platform}-${process.arch};`,
        `server (${serverKind}): ${serverPath};`,
        `Tree-sitter sidecar: ${sidecarPath ?? 'not resolved'}.`,
    ].join(' ');
}

function workspaceDirectory(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}
