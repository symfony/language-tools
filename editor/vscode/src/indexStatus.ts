import * as path from 'node:path';
import * as vscode from 'vscode';
import { LanguageClient, State } from 'vscode-languageclient/node';

const refreshCommand = 'symfony.refreshIndex';
const statusCommand = 'symfony.indexStatus';
const switchEnvironmentCommand = 'symfony.switchEnvironment';

interface IndexSection {
    state: string;
    error?: string;
}

export function indexStatusPollingEnabled(state: State): boolean {
    return State.Running === state;
}

export interface IndexStatus {
    root: string;
    environment: string;
    runtimeEnabled: boolean;
    trusted: boolean;
    source: IndexSection;
    runtime: IndexSection;
}

export class IndexStatusController implements vscode.Disposable {
    private statuses: IndexStatus[] = [];
    private timer: NodeJS.Timeout | undefined;
    private scheduledRefresh: NodeJS.Timeout | undefined;
    private pending: Promise<IndexStatus[]> | undefined;

    public constructor(
        private readonly client: LanguageClient,
        private readonly statusBar: vscode.StatusBarItem,
        private readonly output: vscode.LogOutputChannel,
    ) {
        this.statusBar.name = 'Symfony LSP';
        this.statusBar.command = 'symfonyLsp.indexStatus';
        this.statusBar.text = '$(sync~spin) Symfony';
        this.statusBar.tooltip = 'Symfony LSP is starting.';
        this.statusBar.show();
    }

    public start(context: vscode.ExtensionContext): void {
        context.subscriptions.push(
            vscode.commands.registerCommand('symfonyLsp.refreshIndex', () => this.execute(refreshCommand)),
            vscode.commands.registerCommand('symfonyLsp.indexStatus', () => this.showStatus()),
            vscode.commands.registerCommand('symfonyLsp.switchEnvironment', (environment?: string, root?: string) => this.switchEnvironment(environment, root)),
            vscode.window.onDidChangeActiveTextEditor(() => this.render()),
            vscode.workspace.onDidSaveTextDocument(() => this.scheduleRefresh()),
            this.client.onDidChangeState(({ newState }) => this.handleClientState(newState)),
        );
        this.handleClientState(this.client.state);
    }

    public dispose(): void {
        this.stopPolling();
        this.cancelScheduledRefresh();
        this.statusBar.dispose();
    }

    private async execute(command: string, arguments_: unknown[] = []): Promise<IndexStatus[]> {
        if (!indexStatusPollingEnabled(this.client.state)) {
            void vscode.window.showErrorMessage('Symfony LSP server is not running.');

            return this.statuses;
        }

        const statuses = await this.client.sendRequest<IndexStatus[]>('workspace/executeCommand', {
            command,
            arguments: arguments_,
        });
        this.statuses = statuses ?? [];
        this.render();

        return this.statuses;
    }

    private refresh(): Promise<IndexStatus[]> {
        if (!indexStatusPollingEnabled(this.client.state)) {
            return Promise.resolve(this.statuses);
        }

        if (!this.pending) {
            this.pending = this.execute(statusCommand)
                .catch((error: unknown) => {
                    if (indexStatusPollingEnabled(this.client.state)) {
                        this.output.error(`Could not read index status: ${String(error)}`);
                        this.statusBar.text = '$(error) Symfony';
                        this.statusBar.tooltip = 'Symfony LSP index status is unavailable.';
                    }

                    return this.statuses;
                })
                .finally(() => {
                    this.pending = undefined;
                });
        }

        return this.pending;
    }

    private async showStatus(): Promise<IndexStatus[]> {
        if (!indexStatusPollingEnabled(this.client.state)) {
            void vscode.window.showErrorMessage('Symfony LSP server is not running.');

            return this.statuses;
        }

        const statuses = await this.refresh();
        const message = 0 === statuses.length
            ? 'Symfony LSP did not discover a Symfony application.'
            : statuses.map((status) => this.statusDescription(status)).join('\n');
        void vscode.window.showInformationMessage(message);

        return statuses;
    }

    private async switchEnvironment(environment?: string, root?: string): Promise<IndexStatus[] | undefined> {
        const current = this.currentStatus();
        const selectedEnvironment = environment ?? await vscode.window.showInputBox({
            prompt: 'Symfony environment used for runtime indexing',
            value: current?.environment ?? 'dev',
            validateInput: (value) => /^[A-Za-z0-9_.-]+$/.test(value) ? undefined : 'Use letters, numbers, dots, underscores or hyphens.',
        });
        if (!selectedEnvironment) {
            return undefined;
        }

        return this.execute(switchEnvironmentCommand, [root ?? current?.root ?? null, selectedEnvironment]);
    }

    private scheduleRefresh(): void {
        if (!indexStatusPollingEnabled(this.client.state)) {
            return;
        }

        this.cancelScheduledRefresh();
        this.scheduledRefresh = setTimeout(() => {
            this.scheduledRefresh = undefined;
            void this.refresh();
        }, 500);
    }

    private handleClientState(state: State): void {
        if (indexStatusPollingEnabled(state)) {
            if (!this.timer) {
                this.timer = setInterval(() => void this.refresh(), 5_000);
            }
            void this.refresh();

            return;
        }

        this.stopPolling();
        this.cancelScheduledRefresh();
        const description = State.Starting === state
            ? 'Symfony LSP is starting.'
            : 'Symfony LSP server is not running.';
        this.statusBar.text = State.Starting === state ? '$(sync~spin) Symfony' : '$(error) Symfony';
        this.statusBar.tooltip = description;
        this.statusBar.accessibilityInformation = { label: description };
    }

    private stopPolling(): void {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = undefined;
        }
    }

    private cancelScheduledRefresh(): void {
        if (this.scheduledRefresh) {
            clearTimeout(this.scheduledRefresh);
            this.scheduledRefresh = undefined;
        }
    }

    private render(): void {
        const status = this.currentStatus();
        if (!status) {
            this.statusBar.text = '$(circle-outline) Symfony';
            this.statusBar.tooltip = 'Symfony LSP did not discover a Symfony application.';

            return;
        }

        const runtimeActive = status.runtimeEnabled && status.trusted;
        if ('failed' === status.source.state || (runtimeActive && 'failed' === status.runtime.state)) {
            this.statusBar.text = '$(error) Symfony';
        } else if ('indexing' === status.source.state || (runtimeActive && 'indexing' === status.runtime.state)) {
            this.statusBar.text = '$(sync~spin) Symfony';
        } else if (!runtimeActive) {
            this.statusBar.text = '$(shield) Symfony: static';
        } else if ('stale' === status.runtime.state) {
            this.statusBar.text = '$(warning) Symfony';
        } else if ('ready' === status.source.state && 'ready' === status.runtime.state) {
            this.statusBar.text = `$(check) Symfony: ${status.environment}`;
        } else {
            this.statusBar.text = `$(circle-outline) Symfony: ${status.environment}`;
        }
        this.statusBar.tooltip = this.statusDescription(status);
        this.statusBar.accessibilityInformation = {
            label: this.statusDescription(status),
        };
    }

    private currentStatus(): IndexStatus | undefined {
        const activePath = vscode.window.activeTextEditor?.document.uri.fsPath;
        if (!activePath) {
            return this.statuses[0];
        }

        return [...this.statuses]
            .sort((left, right) => right.root.length - left.root.length)
            .find((status) => activePath === status.root || activePath.startsWith(status.root + path.sep))
            ?? this.statuses[0];
    }

    private statusDescription(status: IndexStatus): string {
        const runtime = !status.runtimeEnabled
            ? 'disabled'
            : status.trusted ? status.runtime.state : 'static only';
        const errors = [status.source.error, status.runtime.error].filter((error): error is string => undefined !== error);
        const summary = `${status.root}: source ${status.source.state}, runtime ${runtime}, environment ${status.environment}`;

        return 0 === errors.length ? summary : `${summary}. ${errors.join(' ')}`;
    }
}
