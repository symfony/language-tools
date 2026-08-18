import * as fs from 'node:fs';
import * as net from 'node:net';
import * as path from 'node:path';
import * as vscode from 'vscode';
import {
    LanguageClient,
    LanguageClientOptions,
    ServerOptions,
    TransportKind,
} from 'vscode-languageclient/node';
import { IndexStatusController } from './indexStatus';

let client: LanguageClient | undefined;

export async function activate(context: vscode.ExtensionContext): Promise<void> {
    const configuration = vscode.workspace.getConfiguration('symfonyLsp', vscode.workspace.workspaceFolders?.[0]?.uri ?? null);
    const configuredServerPath = configuration.get<string>('serverPath', '').trim();
    const serverPath = configuredServerPath || findServerPath(context.extensionPath);

    if (!serverPath || !fs.existsSync(serverPath)) {
        void vscode.window.showErrorMessage(
            'Symfony Language Tools executable not found. Install the extension package for this platform or configure symfonyLsp.serverPath.',
        );

        return;
    }

    const serverOptions: ServerOptions = {
        command: serverPath,
        // micro cannot serve stdio on Windows, so the client listens on a loopback socket there.
        transport: useSocketTransport() ? { kind: TransportKind.socket, port: await findFreePort() } : undefined,
        options: {
            cwd: workspaceDirectory(),
            env: serverEnvironment(configuration.get<string>('memoryLimit', '').trim()),
        },
    };
    const outputChannel = vscode.window.createOutputChannel('Symfony Language Tools', { log: true });
    context.subscriptions.push(outputChannel);
    const extensionVersion = 'string' === typeof context.extension.packageJSON.version
        ? context.extension.packageJSON.version
        : 'unknown';
    const bundledServerDirectory = path.resolve(context.extensionPath, 'server') + path.sep;
    const serverKind = configuredServerPath
        ? 'configured'
        : serverPath.startsWith(bundledServerDirectory) ? 'bundled' : 'development';
    outputChannel.info(serverStartupMessage(extensionVersion, serverPath, serverKind, useSocketTransport() ? 'socket' : 'stdio'));
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
            containerProjectRoot: configuration.get<string>('containerProjectRoot', ''),
            consolePath: configuration.get<string>('consolePath', 'bin/console'),
            environment: configuration.get<string>('environment', 'dev'),
            debug: configuration.get<boolean>('debug', true),
            runtimeIndexing: configuration.get<boolean>('runtimeIndexing', true),
            bridgeTimeout: configuration.get<number>('bridgeTimeout', 300),
            projectRoots: configuration.get<string[]>('projectRoots', []),
            trace: configuration.get<string>('trace', 'off'),
        },
        synchronize: {
            configurationSection: 'symfonyLsp',
        },
    };

    client = new LanguageClient(
        'symfonyLsp',
        'Symfony Language Tools',
        serverOptions,
        clientOptions,
    );
    context.subscriptions.push(client);
    await client.start();
    const serverVersion = client.initializeResult?.serverInfo?.version;
    outputChannel.info(`Symfony Language Tools server ${'string' === typeof serverVersion ? serverVersion : 'unknown'} initialized.`);

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

export function serverEnvironment(memoryLimit = ''): NodeJS.ProcessEnv | undefined {
    if (!memoryLimit) {
        return undefined;
    }

    return { ...process.env, SYMFONY_LSP_MEMORY_LIMIT: memoryLimit };
}

export function serverStartupMessage(
    extensionVersion: string,
    serverPath: string,
    serverKind: 'bundled' | 'configured' | 'development',
    transport: 'socket' | 'stdio',
): string {
    return [
        `Symfony Language Tools extension ${extensionVersion} starting on ${process.platform}-${process.arch};`,
        `server (${serverKind}): ${serverPath};`,
        `transport: ${transport}.`,
    ].join(' ');
}

export function useSocketTransport(): boolean {
    return 'win32' === process.platform;
}

function findFreePort(): Promise<number> {
    return new Promise((resolve, reject) => {
        const probe = net.createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const address = probe.address();
            probe.close(() => {
                if (address && 'object' === typeof address) {
                    resolve(address.port);
                } else {
                    reject(new Error('Unable to find a free port for the language server.'));
                }
            });
        });
    });
}

function workspaceDirectory(): string | undefined {
    return vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
}
