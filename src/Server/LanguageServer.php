<?php

namespace Symfony\Lsp\Server;

use Amp\Cancellation;
use Amp\CancelledException;
use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Feature\CodeActionProviderRegistry;
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
use Symfony\Lsp\Project\UriToPathConverter;
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
        private readonly CodeActionProviderRegistry $codeActionProviders,
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
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ServerLogger $logger,
        private readonly WorkDoneProgressReporter $progress,
        private readonly string $version,
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
        $this->dispatcher->onNotification('workspace/didChangeWorkspaceFolders', $this->changeWorkspaceFolders(...));
        $this->dispatcher->onNotification('workspace/didChangeWatchedFiles', $this->changeWatchedFiles(...));
        $this->dispatcher->onNotification('$/setTrace', $this->setTrace(...));
        $this->dispatcher->onRequest('textDocument/completion', $this->guarded($this->completionProviders->complete(...)));
        $this->dispatcher->onRequest('textDocument/codeAction', $this->guarded($this->codeActionProviders->actions(...)));
        $this->dispatcher->onRequest('textDocument/codeLens', $this->guarded($this->codeLensProviders->codeLenses(...)));
        $this->dispatcher->onRequest('textDocument/hover', $this->guarded($this->hoverProviders->hover(...)));
        $this->dispatcher->onRequest('textDocument/definition', $this->guarded($this->definitionProviders->definition(...)));
        $this->dispatcher->onRequest('textDocument/documentLink', $this->guarded($this->documentLinkProviders->links(...)));
        $this->dispatcher->onRequest('textDocument/references', $this->guarded($this->referencesProviders->references(...)));
        $this->dispatcher->onRequest('textDocument/prepareRename', $this->guarded($this->renameProviders->prepare(...)));
        $this->dispatcher->onRequest('textDocument/rename', $this->guarded($this->renameProviders->rename(...)));
        $this->dispatcher->onRequest('workspace/executeCommand', $this->executeCommand(...));
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

        $this->progress->initialize($params);
        $this->workspaceConfiguration->initialize($params);
        $initializationOptions = $params['initializationOptions'] ?? null;
        if (\is_array($initializationOptions) && \is_string($initializationOptions['trace'] ?? null)) {
            $this->logger->configure($initializationOptions['trace']);
        }
        $this->state->markInitialized();

        return [
            'capabilities' => [
                'positionEncoding' => $this->workspaceConfiguration->positionEncoding(),
                'textDocumentSync' => 2,
                'completionProvider' => [
                    'triggerCharacters' => ["'", '"', '@', '%'],
                ],
                'codeActionProvider' => true,
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
                        IndexCommandHandler::SWITCH_ENVIRONMENT_COMMAND,
                    ],
                ],
                'workspace' => [
                    'workspaceFolders' => [
                        'supported' => true,
                        'changeNotifications' => true,
                    ],
                ],
            ],
            'serverInfo' => [
                'name' => 'Symfony LSP',
                'version' => $this->version,
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
        $sourceFileChange = $this->sourceScanner->refreshAfterSave($params);
        $this->projectRuntimeRefresher->refreshAfterSave($params, $sourceFileChange);
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
        async(function (): void {
            $this->workspaceConfiguration->refreshProjectSettings();
            $this->workspaceConfiguration->requestWorkspaceTrust();
            $this->diagnosticProviders->refreshAll();
        })->ignore();
    }

    /** @param array<array-key, mixed> $params */
    private function changeWorkspaceFolders(array $params): void
    {
        async(function () use ($params): void {
            $this->workspaceConfiguration->changeWorkspaceFolders($params);
            $this->workspaceConfiguration->refreshProjectSettings();
            $this->sourceScanner->indexAll();
            $this->workspaceConfiguration->requestWorkspaceTrust();
        })->ignore();
    }

    /** @param array<array-key, mixed> $params */
    private function changeWatchedFiles(array $params): void
    {
        $changes = $params['changes'] ?? null;
        if (!\is_array($changes)) {
            return;
        }

        $rediscover = false;
        foreach ($changes as $change) {
            if (!\is_array($change) || !\is_string($change['uri'] ?? null) || !\is_int($change['type'] ?? null)) {
                continue;
            }
            $deleted = 3 === $change['type'];
            $sourceFileChange = $this->sourceScanner->refreshUri($change['uri'], $deleted);
            $this->projectRuntimeRefresher->refreshUri($change['uri'], $sourceFileChange);
            if ('composer.json' === basename($this->uriToPathConverter->convert($change['uri']) ?? '')) {
                $rediscover = true;
            }
        }

        if ($rediscover) {
            $this->workspaceConfiguration->rediscoverProjects();
            $this->sourceScanner->indexAll();
            $this->workspaceConfiguration->requestWorkspaceTrust();
        }
    }

    /** @param array<array-key, mixed> $params */
    private function setTrace(array $params): void
    {
        if (\is_string($params['value'] ?? null)) {
            $this->logger->configure($params['value']);
        }
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
     *
     * @return list<array{root: string, environment: string, runtimeEnabled: bool, trusted: bool, source: array{state: string, error?: string}, runtime: array{state: string, error?: string}}>|null
     */
    private function executeCommand(array $params, Cancellation $cancellation): ?array
    {
        $this->assertRunning();

        return $this->indexCommandHandler->execute($params, $cancellation);
    }

    private function shutdown(): null
    {
        if (!$this->state->isInitialized()) {
            throw new JsonRpcException(JsonRpcError::INVALID_REQUEST, 'The server has not been initialized.');
        }

        $this->state->markShutdown();

        return null;
    }

    private function exit(): void
    {
        $this->state->requestExit();
    }

    /**
     * @param callable(array<array-key, mixed>): mixed $handler
     *
     * @return \Closure(array<array-key, mixed>, Cancellation): mixed
     */
    private function guarded(callable $handler): \Closure
    {
        return function (array $params, Cancellation $cancellation) use ($handler): mixed {
            try {
                $this->assertRunning();
                $cancellation->throwIfRequested();
                $result = $handler($params);
                $cancellation->throwIfRequested();

                return $result;
            } catch (CancelledException) {
                throw new JsonRpcException(-32800, 'Request cancelled.');
            }
        };
    }

    private function assertRunning(): void
    {
        if (!$this->state->isInitialized()) {
            throw new JsonRpcException(-32002, 'The server has not been initialized.');
        }
        if ($this->state->isShutdown()) {
            throw new JsonRpcException(JsonRpcError::INVALID_REQUEST, 'The server has shut down.');
        }
    }
}
