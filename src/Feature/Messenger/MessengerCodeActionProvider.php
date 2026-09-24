<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\EnvironmentScopeResolver;

final class MessengerCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
        private readonly EnvironmentScopeResolver $environments,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request || !\is_array($context) || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
            || !$this->environments->includesDocument($request->project, $request->document->uri)
        ) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || !\in_array($diagnostic['code'] ?? null, ['messenger.unknown_bus', 'messenger.unknown_transport'], true)
                || !\is_array($diagnostic['range'] ?? null)
            ) {
                continue;
            }
            foreach ($facts instanceof MessengerSourceFacts ? $facts->symbols : [] as $symbol) {
                if ($symbol->declaration || !$this->environments->includesSection($request->project, $symbol->environment)
                    || !$this->protocol->sameRange($symbol->range, $diagnostic['range'])
                    || (MessengerSymbolKind::Bus === $symbol->kind ? 'messenger.unknown_bus' : 'messenger.unknown_transport') !== $diagnostic['code']
                    || (MessengerSymbolKind::Bus === $symbol->kind ? null !== $index->bus($symbol->name) : null !== $index->transport($symbol->name))
                ) {
                    continue;
                }
                $candidates = MessengerSymbolKind::Bus === $symbol->kind
                    ? array_map(static fn (MessengerBus $bus): string => $bus->name, $index->buses())
                    : array_map(static fn (MessengerTransport $transport): string => $transport->name, $index->transports());
                array_push($actions, ...$this->unknownNames->replacements($request->document, $diagnostic, $symbol->range, $symbol->name, $candidates));
                break;
            }
        }

        return $actions;
    }
}
