<?php

namespace Symfony\Lsp\Server;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Feature\CodeLensProviderRegistry;
use Symfony\Lsp\Feature\CompletionProviderRegistry;
use Symfony\Lsp\Feature\DefinitionProviderRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Feature\DocumentLinkProviderRegistry;
use Symfony\Lsp\Feature\HoverProviderRegistry;
use Symfony\Lsp\Feature\ReferencesProviderRegistry;
use Symfony\Lsp\Feature\RenameProviderRegistry;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;

use function Amp\async;

final class LanguageServer
{
    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly JsonRpcDispatcher $dispatcher,
        private readonly ServerState $state,
        private readonly WorkspaceConfiguration $workspaceConfiguration,
        private readonly DocumentSynchronizer $documentSynchronizer,
        private readonly CompletionProviderRegistry $completionProviders,
        private readonly CodeLensProviderRegistry $codeLensProviders,
        private readonly HoverProviderRegistry $hoverProviders,
        private readonly DiagnosticProviderRegistry $diagnosticProviders,
        private readonly DefinitionProviderRegistry $definitionProviders,
        private readonly DocumentLinkProviderRegistry $documentLinkProviders,
        private readonly ReferencesProviderRegistry $referencesProviders,
        private readonly RenameProviderRegistry $renameProviders,
        private readonly ProjectRuntimeRefresher $projectRuntimeRefresher,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly IndexCommandHandler $indexCommandHandler,
    ) {
        $this->registerHandlers();
    }

    public function run(): int
    {
        $this->peer->listen();

        return $this->state->isExitRequested() && !$this->state->isShutdown() ? 1 : 0;
    }

    private function registerHandlers(): void
    {
        $this->dispatcher->onRequest('initialize', $this->initialize(...));
        $this->dispatcher->onNotification('initialized', $this->initialized(...));
        $this->dispatcher->onNotification('textDocument/didOpen', $this->openDocument(...));
        $this->dispatcher->onNotification('textDocument/didChange', $this->changeDocument(...));
        $this->dispatcher->onNotification('textDocument/didClose', $this->closeDocument(...));
        $this->dispatcher->onNotification('textDocument/didSave', $this->saveDocument(...));
        $this->dispatcher->onNotification('workspace/didChangeConfiguration', $this->changeConfiguration(...));
        $this->dispatcher->onRequest('textDocument/completion', $this->completionProviders->complete(...));
        $this->dispatcher->onRequest('textDocument/codeLens', $this->codeLensProviders->codeLenses(...));
        $this->dispatcher->onRequest('textDocument/hover', $this->hoverProviders->hover(...));
        $this->dispatcher->onRequest('textDocument/definition', $this->definitionProviders->definition(...));
        $this->dispatcher->onRequest('textDocument/documentLink', $this->documentLinkProviders->links(...));
        $this->dispatcher->onRequest('textDocument/references', $this->referencesProviders->references(...));
        $this->dispatcher->onRequest('textDocument/prepareRename', $this->renameProviders->prepare(...));
        $this->dispatcher->onRequest('textDocument/rename', $this->renameProviders->rename(...));
        $this->dispatcher->onRequest('workspace/executeCommand', $this->indexCommandHandler->execute(...));
        $this->dispatcher->onRequest('shutdown', $this->shutdown(...));
        $this->dispatcher->onNotification('exit', $this->exit(...));
        $this->dispatcher->onCancel('$/cancelRequest', 'id');
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{capabilities: array<string, mixed>, serverInfo: array{name: string, version: string}}
     */
    private function initialize(array $params): array
    {
        if ($this->state->isInitialized()) {
            throw new JsonRpcException(JsonRpcError::INVALID_REQUEST, 'The server is already initialized.');
        }

        $this->workspaceConfiguration->initialize($params);
        $this->state->markInitialized();

        return [
            'capabilities' => [
                'positionEncoding' => 'utf-16',
                'textDocumentSync' => 2,
                'completionProvider' => [
                    'triggerCharacters' => ["'", '"', '@', '%'],
                ],
                'codeLensProvider' => [
                    'resolveProvider' => false,
                ],
                'hoverProvider' => true,
                'definitionProvider' => true,
                'documentLinkProvider' => [
                    'resolveProvider' => false,
                ],
                'referencesProvider' => true,
                'renameProvider' => [
                    'prepareProvider' => true,
                ],
                'executeCommandProvider' => [
                    'commands' => [
                        IndexCommandHandler::REFRESH_COMMAND,
                        IndexCommandHandler::STATUS_COMMAND,
                    ],
                ],
            ],
            'serverInfo' => [
                'name' => 'Symfony LSP',
                'version' => 'dev',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function openDocument(array $params): void
    {
        $this->documentSynchronizer->open($params);
        $this->sourceScanner->updateOpenDocument($params);
        $this->diagnosticProviders->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function changeDocument(array $params): void
    {
        $this->documentSynchronizer->change($params);
        $this->sourceScanner->updateOpenDocument($params);
        $this->diagnosticProviders->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function saveDocument(array $params): void
    {
        $this->sourceScanner->refreshAfterSave($params);
        $this->projectRuntimeRefresher->refreshAfterSave($params);
        $this->diagnosticProviders->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function closeDocument(array $params): void
    {
        $this->documentSynchronizer->close($params);
        $this->sourceScanner->restoreClosedDocument($params);
        $this->diagnosticProviders->clear($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function changeConfiguration(array $params): void
    {
        $this->workspaceConfiguration->refreshProjectSettings();
        $this->diagnosticProviders->refreshAll();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function initialized(array $params): void
    {
        async(function (): void {
            $this->workspaceConfiguration->refreshProjectSettings();
            $this->sourceScanner->indexAll();
            $this->workspaceConfiguration->requestWorkspaceTrust();
        })->ignore();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function shutdown(array $params): null
    {
        if (!$this->state->isInitialized()) {
            throw new JsonRpcException(JsonRpcError::INVALID_REQUEST, 'The server has not been initialized.');
        }

        $this->state->markShutdown();

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function exit(array $params): void
    {
        $this->state->requestExit();
    }
}
