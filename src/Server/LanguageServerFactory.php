<?php

namespace Symfony\Lsp\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Fabpot\JsonRpc\JsonRpcDispatcher;
use Fabpot\JsonRpc\JsonRpcPeer;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Protocol\ContentLengthMessageReader;
use Symfony\Lsp\Protocol\ContentLengthMessageWriter;
use Symfony\Lsp\Protocol\ContentLengthReadableStream;
use Symfony\Lsp\Protocol\ContentLengthWritableStream;

final class LanguageServerFactory
{
    public function create(ReadableStream $input, WritableStream $output): LanguageServer
    {
        $input = new ContentLengthReadableStream($input, new ContentLengthMessageReader($input));
        $output = new ContentLengthWritableStream($output, new ContentLengthMessageWriter($output));
        $peer = new JsonRpcPeer($input, $output);

        $workspaceConfiguration = new WorkspaceConfiguration(
            new ProjectDiscovery(new UriToPathConverter()),
            new ProjectRegistry(),
        );

        return new LanguageServer(
            $peer,
            new JsonRpcDispatcher($peer),
            new ServerState(),
            $workspaceConfiguration,
        );
    }
}
