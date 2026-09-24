<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SecurityCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request || !\is_array($context) || !$this->paths->isApplicationOwned($request->project, $request->document->uri)) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $facts = $sourceIndex->factsForUri($request->document->uri);
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || !\in_array($diagnostic['code'] ?? null, ['security.unknown_firewall', 'security.unknown_provider'], true)
                || !\is_array($diagnostic['range'] ?? null)
            ) {
                continue;
            }
            foreach ($facts instanceof SecuritySourceFacts ? $facts->symbols : [] as $symbol) {
                if ($symbol->declaration || SecuritySymbolKind::Role === $symbol->kind
                    || !$this->protocol->sameRange($symbol->range, $diagnostic['range'])
                    || (SecuritySymbolKind::Firewall === $symbol->kind ? 'security.unknown_firewall' : 'security.unknown_provider') !== $diagnostic['code']
                    || (SecuritySymbolKind::Firewall === $symbol->kind ? null !== $index->firewall($symbol->name) : null !== $index->provider($symbol->name))
                    || \in_array($symbol->name, $sourceIndex->declarationNames($symbol->kind), true)
                ) {
                    continue;
                }
                $names = SecuritySymbolKind::Firewall === $symbol->kind
                    ? array_map(static fn (SecurityFirewall $firewall): string => $firewall->name, $index->firewalls())
                    : array_map(static fn (SecurityUserProviderDeclaration $provider): string => $provider->name, $index->providers());
                array_push($names, ...$sourceIndex->declarationNames($symbol->kind));
                array_push($actions, ...$this->unknownNames->replacements($request->document, $diagnostic, $symbol->range, $symbol->name, $names));
                break;
            }
        }

        return $actions;
    }
}
