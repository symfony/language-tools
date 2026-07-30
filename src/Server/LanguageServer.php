<?php

namespace Symfony\Lsp\Server;

use Fabpot\JsonRpc\Exception\JsonRpcException;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcError;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Document\DocumentSynchronizer;
use Symfony\Lsp\Feature\Route\ProjectRouteSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Feature\Route\RouteDefinitionHandler;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteHoverHandler;
use Symfony\Lsp\Feature\Route\RouteReferencesHandler;
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
        private readonly RouteCompletionHandler $routeCompletionHandler,
        private readonly RouteHoverHandler $routeHoverHandler,
        private readonly RouteDiagnosticPublisher $routeDiagnosticPublisher,
        private readonly RouteDefinitionHandler $routeDefinitionHandler,
        private readonly RouteReferencesHandler $routeReferencesHandler,
        private readonly ProjectRuntimeRefresher $projectRuntimeRefresher,
        private readonly ProjectRouteSourceIndexer $routeSourceIndexer,
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
        $this->dispatcher->onRequest('textDocument/completion', $this->routeCompletionHandler->complete(...));
        $this->dispatcher->onRequest('textDocument/hover', $this->routeHoverHandler->hover(...));
        $this->dispatcher->onRequest('textDocument/definition', $this->routeDefinitionHandler->definition(...));
        $this->dispatcher->onRequest('textDocument/references', $this->routeReferencesHandler->references(...));
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
                'hoverProvider' => true,
                'definitionProvider' => true,
                'referencesProvider' => true,
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
        $this->routeDiagnosticPublisher->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function changeDocument(array $params): void
    {
        $this->documentSynchronizer->change($params);
        $this->routeDiagnosticPublisher->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function saveDocument(array $params): void
    {
        $this->routeSourceIndexer->refreshAfterSave($params);
        $this->projectRuntimeRefresher->refreshAfterSave($params);
        $this->routeDiagnosticPublisher->publish($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function closeDocument(array $params): void
    {
        $this->documentSynchronizer->close($params);
        $this->routeDiagnosticPublisher->clear($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function initialized(array $params): void
    {
        async(function (): void {
            $this->routeSourceIndexer->indexAll();
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
