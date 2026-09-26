<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;

final class SecurityCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if (!$this->paths->isApplicationOwned($request->project, $request->document->uri)) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $facts = $sourceIndex->factsForUri($request->document->uri);

        return $this->unknownNames->actions(
            $request,
            ['security.unknown_firewall', 'security.unknown_provider'],
            $facts instanceof SecuritySourceFacts ? $facts->symbols : [],
            static function (SecuritySourceSymbol $symbol, string $code) use ($index, $sourceIndex): ?array {
                $firewall = SecuritySymbolKind::Firewall === $symbol->kind;
                $declared = $sourceIndex->declarationNames($symbol->kind);
                if ($symbol->declaration
                    || SecuritySymbolKind::Role === $symbol->kind
                    || ($firewall ? 'security.unknown_firewall' : 'security.unknown_provider') !== $code
                    || ($firewall ? null !== $index->firewall($symbol->name) : null !== $index->provider($symbol->name))
                    || \in_array($symbol->name, $declared, true)
                ) {
                    return null;
                }
                $names = $firewall
                    ? array_map(static fn (SecurityFirewall $candidate): string => $candidate->name, $index->firewalls())
                    : array_map(static fn (SecurityUserProviderDeclaration $candidate): string => $candidate->name, $index->providers());

                return [$symbol->name, [...$names, ...$declared]];
            },
        );
    }
}
