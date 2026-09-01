<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SecurityDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    public function name(): string
    {
        return 'security';
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
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->extract(new SourceDocument($request->document->uri, $request->document->languageId, $request->document->text))->symbols as $symbol) {
            if ($symbol->declaration || SecuritySymbolKind::Role === $symbol->kind) {
                continue;
            }
            $known = SecuritySymbolKind::Firewall === $symbol->kind
                ? null !== $index->firewall($symbol->name)
                : null !== $index->provider($symbol->name);
            if (!$known && !\in_array($symbol->name, $sourceIndex->declarationNames($symbol->kind), true)) {
                $diagnostics[] = $this->protocol->diagnostic($symbol->range, 1, SecuritySymbolKind::Firewall === $symbol->kind ? 'security.unknown_firewall' : 'security.unknown_provider', \sprintf('Unknown security %s "%s".', $symbol->kind->value, $symbol->name));
            }
        }

        return $diagnostics;
    }
}
