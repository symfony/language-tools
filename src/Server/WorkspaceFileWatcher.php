<?php

namespace Symfony\Lsp\Server;

use Symfony\Lsp\Client\ClientInterface;

final class WorkspaceFileWatcher
{
    private bool $supported = false;
    private bool $registered = false;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @param array<array-key, mixed> $initializeParams */
    public function initialize(array $initializeParams): void
    {
        $capabilities = $initializeParams['capabilities'] ?? null;
        $workspace = \is_array($capabilities) ? ($capabilities['workspace'] ?? null) : null;
        $watchedFiles = \is_array($workspace) ? ($workspace['didChangeWatchedFiles'] ?? null) : null;
        $this->supported = \is_array($watchedFiles) && true === ($watchedFiles['dynamicRegistration'] ?? null);
    }

    public function register(): void
    {
        if (!$this->supported || $this->registered) {
            return;
        }

        try {
            $this->client->request('client/registerCapability', [
                'registrations' => [[
                    'id' => 'symfony-lsp-workspace-files',
                    'method' => 'workspace/didChangeWatchedFiles',
                    'registerOptions' => [
                        'watchers' => [
                            ['globPattern' => '**/*.{php,twig,yaml,yml,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}'],
                            ['globPattern' => '**/.env*'],
                            ['globPattern' => '**/composer.{json,lock}'],
                        ],
                    ],
                ]],
            ]);
            $this->registered = true;
        } catch (\Throwable) {
        }
    }
}
