<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Runtime\EnvironmentScopeResolver;

final class MessengerCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
        private readonly EnvironmentScopeResolver $environments,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if (!$this->paths->isApplicationOwned($request->project, $request->document->uri)
            || !$this->environments->includesDocument($request->project, $request->document->uri)
        ) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);

        return $this->unknownNames->actions(
            $request,
            ['messenger.unknown_bus', 'messenger.unknown_transport'],
            $facts instanceof MessengerSourceFacts ? $facts->symbols : [],
            function (MessengerSourceSymbol $symbol, string $code) use ($request, $index): ?array {
                $bus = MessengerSymbolKind::Bus === $symbol->kind;
                if ($symbol->declaration
                    || !$this->environments->includesSection($request->project, $symbol->environment)
                    || ($bus ? 'messenger.unknown_bus' : 'messenger.unknown_transport') !== $code
                    || ($bus ? null !== $index->bus($symbol->name) : null !== $index->transport($symbol->name))
                ) {
                    return null;
                }

                return [$symbol->name, $bus
                    ? array_map(static fn (MessengerBus $candidate): string => $candidate->name, $index->buses())
                    : array_map(static fn (MessengerTransport $candidate): string => $candidate->name, $index->transports())];
            },
        );
    }
}
