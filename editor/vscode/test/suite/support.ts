import * as assert from 'node:assert/strict';
import * as vscode from 'vscode';

let currentWorkspace: vscode.WorkspaceFolder;

export type TestCase = [string, () => Promise<void>];

export function setWorkspace(workspace: vscode.WorkspaceFolder): void {
    currentWorkspace = workspace;
}

export function workspace(): vscode.WorkspaceFolder {
    return currentWorkspace;
}

export async function open(relativePath: string): Promise<vscode.TextDocument> {
    const document = await vscode.workspace.openTextDocument(vscode.Uri.joinPath(currentWorkspace.uri, relativePath));
    await vscode.window.showTextDocument(document, { preview: false });

    return document;
}

export async function withTemporaryDocument<T>(relativePath: string, contents: string, test: (document: vscode.TextDocument) => Promise<T>, languageId?: string): Promise<T> {
    const directory = vscode.Uri.joinPath(currentWorkspace.uri, '.lsp-e2e');
    const uri = vscode.Uri.joinPath(directory, relativePath);
    await vscode.workspace.fs.createDirectory(directory);
    await vscode.workspace.fs.writeFile(uri, Buffer.from(contents));
    let document = await vscode.workspace.openTextDocument(uri);
    if (languageId) {
        document = await vscode.languages.setTextDocumentLanguage(document, languageId);
    }
    await vscode.window.showTextDocument(document, { preview: false });
    try {
        return await test(document);
    } finally {
        await vscode.window.showTextDocument(document, { preview: false });
        await vscode.commands.executeCommand('workbench.action.closeActiveEditor');
        await vscode.workspace.fs.delete(uri, { useTrash: false });
    }
}

export async function cleanupTemporaryDocuments(): Promise<void> {
    const uri = vscode.Uri.joinPath(currentWorkspace.uri, '.lsp-e2e');
    try {
        await vscode.workspace.fs.delete(uri, { recursive: true, useTrash: false });
    } catch (error: unknown) {
        if (!(error instanceof vscode.FileSystemError && 'FileNotFound' === error.code)) {
            throw error;
        }
    }
}

export async function completions(uri: vscode.Uri, position: vscode.Position): Promise<vscode.CompletionItem[]> {
    const result = await vscode.commands.executeCommand<vscode.CompletionList>('vscode.executeCompletionItemProvider', uri, position);

    return result?.items ?? [];
}

export function labels(items: vscode.CompletionItem[]): string[] {
    return items.map((item) => 'string' === typeof item.label ? item.label : item.label.label);
}

export function positionAfter(document: vscode.TextDocument, needle: string): vscode.Position {
    const offset = document.getText().indexOf(needle);
    assert.notEqual(offset, -1, `Could not find "${needle}" in ${document.uri.path}.`);

    return document.positionAt(offset + needle.length);
}

export function positionInside(document: vscode.TextDocument, lineNeedle: string, symbol: string): vscode.Position {
    const lineOffset = document.getText().indexOf(lineNeedle);
    assert.notEqual(lineOffset, -1, `Could not find "${lineNeedle}" in ${document.uri.path}.`);
    const symbolOffset = lineNeedle.indexOf(symbol);
    assert.notEqual(symbolOffset, -1, `Could not find "${symbol}" in "${lineNeedle}".`);

    return document.positionAt(lineOffset + symbolOffset + Math.floor(symbol.length / 2));
}

export function locationPaths(locations: Array<vscode.Location | vscode.LocationLink> | undefined): string[] {
    return (locations ?? []).map((location) => 'uri' in location ? location.uri.path : location.targetUri.path);
}

export function hoverText(hovers: vscode.Hover[] | undefined): string {
    return (hovers ?? []).flatMap((hover) => hover.contents).map((contents) => 'string' === typeof contents ? contents : contents.value).join('\n');
}

export async function waitFor<T>(probe: () => PromiseLike<T>, ready: (value: T) => boolean, description: string): Promise<T> {
    const deadline = Date.now() + 10_000;
    let lastValue: T | undefined;
    let lastError: unknown;
    while (Date.now() < deadline) {
        try {
            lastValue = await probe();
            if (ready(lastValue)) {
                return lastValue;
            }
        } catch (error: unknown) {
            lastError = error;
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error(`Timed out waiting for ${description}. Last value: ${JSON.stringify(lastValue)}. Last error: ${String(lastError)}`);
}
