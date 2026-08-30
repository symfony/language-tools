<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly MetadataExtractor $extractor,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $symbols = $this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name);
        $count = \count($symbols);
        $value = match ($symbol->kind) {
            MetadataSymbolKind::Constraint => 'Validation constraint: `'.$symbol->name.'`',
            MetadataSymbolKind::MappedClass => 'Mapped class: `'.$symbol->name.'`',
            MetadataSymbolKind::Property => $this->propertyHover($symbol, $symbols),
            MetadataSymbolKind::SerializerGroup => \sprintf("Serializer group: `%s`\n\n%d known occurrence%s", $symbol->name, $count, 1 === $count ? '' : 's'),
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
        foreach ($this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name) as $candidate) {
            if ($candidate->declaration) {
                $locations[] = $this->protocol->location($candidate->uri, $candidate->range);
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
        foreach ($this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name) as $candidate) {
            $locations[] = $this->protocol->location($candidate->uri, $candidate->range);
        }

        return $locations;
    }

    /** @param list<MetadataSourceSymbol> $symbols */
    private function propertyHover(MetadataSourceSymbol $symbol, array $symbols): string
    {
        $declaration = $symbol->declaration ? $symbol : null;
        foreach ($symbols as $candidate) {
            if ($candidate->declaration) {
                $declaration = $candidate;
                break;
            }
        }

        $value = 'PHP property: `'.$symbol->name.'`';
        if (null !== $declaration?->signature) {
            $value .= "\n\n```php\n".$declaration->signature."\n```";
        }
        if (null !== $declaration?->description) {
            $value .= "\n\n".$declaration->description;
        }

        return $value;
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
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols as $symbol) {
            if ($this->converter->containsByteOffset($request->document->text, $symbol->range, $offset, inclusiveEnd: true)) {
                return [$symbol, $request->project];
            }
        }

        return null;
    }
}
