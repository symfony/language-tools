<?php

namespace Symfony\Lsp\Server;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;

final class LanguageServer
{
    public function __construct(
        private readonly JsonRpcPeer $peer,
        private readonly JsonRpcDispatcher $dispatcher,
        private readonly ServerState $state,
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

        $this->state->markInitialized();

        return [
            'capabilities' => [
                'positionEncoding' => 'utf-16',
                'textDocumentSync' => 2,
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
