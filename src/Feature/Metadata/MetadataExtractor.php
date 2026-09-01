<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class MetadataExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly FormMetadataExtractor $forms,
        private readonly ValidationMetadataExtractor $validation,
        private readonly SerializerMetadataExtractor $serializer,
        private readonly YamlMetadataExtractor $yaml,
    ) {
    }

    public function extract(SourceDocument $document): MetadataSourceFacts
    {
        if ('php' === $document->languageId) {
            $php = $this->phpParser->parse($document->text);
            $source = $this->phpComments->mask($document->text);
            $formDataClasses = $this->forms->dataClasses($source, $php);

            return new MetadataSourceFacts(
                $document->uri,
                $this->unique($this->phpSymbols($document->uri, $document->text, $source, $php, $formDataClasses)),
                $formDataClasses,
            );
        }

        return new MetadataSourceFacts($document->uri, 'yaml' === $document->languageId ? $this->unique($this->yaml->symbols($document->uri, $document->text)) : []);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?MetadataCompletionContext
    {
        if ('php' === $languageId) {
            $php = $this->phpParser->parse($text);
            $source = $this->phpComments->mask($text);

            return $this->serializer->completionContext($text, $source, $php, $offset)
                ?? $this->validation->completionContext($text, $source, $php, $offset)
                ?? $this->forms->completionContext($text, $source, $php, $offset);
        }

        return 'yaml' === $languageId ? $this->yaml->completionContext($text, $offset) : null;
    }

    /**
     * @return list<array{class: string, option: string, range: Range}>
     */
    public function formOptions(string $text): array
    {
        $php = $this->phpParser->parse($text);

        return $this->forms->options($text, $this->phpComments->mask($text), $php);
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function constraintOptions(string $text): array
    {
        return $this->validation->options($text, $this->phpParser->parse($text));
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function yamlConstraintOptions(string $text): array
    {
        return $this->yaml->constraintOptions($text);
    }

    /**
     * @param list<FormDataClass> $formDataClasses
     *
     * @return list<MetadataSourceSymbol>
     */
    private function phpSymbols(string $uri, string $text, string $source, PhpDocument $php, array $formDataClasses): array
    {
        $symbols = [];
        foreach ($php->typeDeclarations as $type) {
            if (!$type->isClass()) {
                continue;
            }
            $range = $this->converter->toRange($text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset);
            $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::MappedClass, $type->name, $uri, $range, true);
            array_push($symbols, ...$this->validation->declarationSymbols($uri, $type, $range));
        }
        foreach ($php->propertyDeclarations as $property) {
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::Property,
                $property->className.'::$'.$property->name,
                $uri,
                $this->converter->toRange($text, $property->nameStartOffset, $property->nameEndOffset - $property->nameStartOffset),
                true,
                $property->signature,
                $property->description,
            );
        }
        array_push($symbols, ...$this->forms->symbols($uri, $text, $php, $formDataClasses));
        array_push($symbols, ...$this->serializer->symbols($uri, $text, $source, $php));
        array_push($symbols, ...$this->validation->referenceSymbols($uri, $text, $php));

        return $symbols;
    }

    /**
     * @param list<MetadataSourceSymbol> $symbols
     *
     * @return list<MetadataSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind->value.'|'.$symbol->range->start->line.'|'.$symbol->range->start->character;
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
