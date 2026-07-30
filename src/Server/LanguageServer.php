<?php

namespace Symfony\Lsp\Server;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Project\WorkspaceConfiguration;

use function Amp\async;

final class LanguageServer
{
    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly JsonRpcDispatcher $dispatcher,
        private readonly ServerState $state,
        private readonly WorkspaceConfiguration $workspaceConfiguration,
        private readonly DocumentSynchronizer $documentSynchronizer,
        private readonly RouteCompletionHandler $routeCompletionHandler,
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
        $this->dispatcher->onNotification('textDocument/didOpen', $this->documentSynchronizer->open(...));
        $this->dispatcher->onNotification('textDocument/didChange', $this->documentSynchronizer->change(...));
        $this->dispatcher->onNotification('textDocument/didClose', $this->documentSynchronizer->close(...));
        $this->dispatcher->onRequest('textDocument/completion', $this->routeCompletionHandler->complete(...));
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
                    'triggerCharacters' => ["'", '"'],
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
    private function initialized(array $params): void
    {
        async($this->workspaceConfiguration->requestWorkspaceTrust(...))->ignore();
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
