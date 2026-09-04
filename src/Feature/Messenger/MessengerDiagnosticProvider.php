<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function name(): string
    {
        return 'messenger';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        if (!$facts instanceof MessengerSourceFacts) {
            return [];
        }
        $diagnostics = [];
        foreach ($facts->symbols as $symbol) {
            if ($symbol->declaration || MessengerSymbolKind::Message === $symbol->kind) {
                continue;
            }
            $known = MessengerSymbolKind::Bus === $symbol->kind ? null !== $index->bus($symbol->name) : null !== $index->transport($symbol->name);
            if (!$known) {
                $diagnostics[] = $this->protocol->diagnostic($symbol->range, 1, MessengerSymbolKind::Bus === $symbol->kind ? 'messenger.unknown_bus' : 'messenger.unknown_transport', \sprintf('Unknown Messenger %s "%s".', strtolower($symbol->kind->name), $symbol->name));
            }
        }
        foreach ($facts->handlerSignatures as $signature) {
            foreach ($index->handlersByClass($signature->className) as $handler) {
                if ($signature->method !== $handler->method) {
                    continue;
                }
                $diagnostics[] = $this->protocol->diagnostic($signature->range, 1, 'messenger.invalid_handler_signature', \sprintf('Messenger handler "%s::%s" cannot accept message "%s".', $handler->className, $handler->method, $handler->message));
            }
        }

        return $diagnostics;
    }
}
