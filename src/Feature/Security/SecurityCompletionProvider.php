<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SecurityCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return null;
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
                $items[] = [
                    'label' => $name,
                    'kind' => 12,
                    'detail' => 'Symfony security '.$context->kind->value,
                    'textEdit' => $this->protocol->textEdit($context->range, $name),
                ];
            }
        }

        return $items;
    }
}
