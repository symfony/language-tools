<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataRelationshipProvider implements CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly MetadataExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $context = $this->extractor->completionContext($request->document->languageId(), $request->document->text(), $offset);
        if (null === $context || MetadataCompletionKind::Property !== $context->kind()) {
            return null;
        }
        $prefix = $context->owner().'::$';
        $names = [];
        foreach ($this->sourceIndexes->forProject($request->project)->symbols(MetadataSymbolKind::Property) as $symbol) {
            if ($symbol->isDeclaration() && str_starts_with($symbol->name(), $prefix)) {
                $names[substr($symbol->name(), \strlen($prefix))] = true;
            }
        }
        ksort($names);
        $items = [];
        foreach (array_keys($names) as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'detail' => 'Mapped property',
                'kind' => 10,
                'textEdit' => $this->protocol->textEdit($context->range(), $name),
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $count = \count($this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()));
        $value = match ($symbol->kind()) {
            MetadataSymbolKind::Constraint => 'Validation constraint: `'.$symbol->name().'`',
            MetadataSymbolKind::MappedClass => 'Mapped class: `'.$symbol->name().'`',
            MetadataSymbolKind::Property => 'Mapped property: `'.$symbol->name().'`',
            MetadataSymbolKind::SerializerGroup => \sprintf("Serializer group: `%s`\n\n%d known occurrence%s", $symbol->name(), $count, 1 === $count ? '' : 's'),
        };

        return $this->protocol->markdownHover($value);
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $locations = [];
        foreach ($this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()) as $candidate) {
            if ($candidate->isDeclaration()) {
                $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
            }
        }

        return $locations;
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $locations = [];
        foreach ($this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()) as $candidate) {
            $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
        }

        return $locations;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{MetadataSourceSymbol, Project}|null
     */
    private function resolveSourceSymbol(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($this->contains($request->document, $symbol->range(), $offset)) {
                return [$symbol, $request->project];
            }
        }

        return null;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }
}
