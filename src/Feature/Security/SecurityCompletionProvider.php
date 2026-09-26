<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class SecurityCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $offset = $request->offset;
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        $names = match ($context->kind) {
            SecuritySymbolKind::Firewall => array_map(static fn (SecurityFirewall $firewall): string => $firewall->name, $index->firewalls()),
            SecuritySymbolKind::Provider => array_map(static fn (SecurityUserProviderDeclaration $provider): string => $provider->name, $index->providers()),
            SecuritySymbolKind::Role => array_map(static fn (SecurityRole $role): string => $role->name, $index->roles()),
        };
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $sourceNames = SecuritySymbolKind::Role === $context->kind
            ? $sourceIndex->names($context->kind)
            : $sourceIndex->declarationNames($context->kind);
        array_push($names, ...$sourceNames);
        $names = array_values(array_unique($names));
        sort($names);
        $items = [];
        foreach ($names as $name) {
            if (str_starts_with($name, $context->prefix)) {
                $items[] = $this->protocol->completionItem(
                    $name,
                    CompletionItemKind::Value,
                    'Symfony security '.$context->kind->value,
                    $this->protocol->textEdit($context->range, $name),
                );
            }
        }

        return $items;
    }
}
