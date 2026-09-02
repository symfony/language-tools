<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpPropertyDeclaration;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class DoctrineExtractor
{
    private const ASSOCIATIONS = ['Embedded', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParser $phpComments,
        private readonly DoctrineRepositoryReceiverResolver $repositoryReceivers,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
    ) {
    }

    public function extract(SourceDocument $document): DoctrineSourceFacts
    {
        if ('php' !== $document->languageId) {
            return new DoctrineSourceFacts($document->uri, [], [], []);
        }
        $php = $this->phpParser->parse($document->text);
        $source = $this->phpComments->mask($document->text);
        $entities = [];
        $repositories = [];
        $symbols = [];
        foreach ($php->typeDeclarations as $type) {
            if (!$type->isClass()) {
                continue;
            }
            $range = $this->converter->toRange($document->text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset);
            if ([] !== $this->mappingAttributes($php, PhpAttributeTargetKind::Type, $type->name, null, ['Entity', 'MappedSuperclass'])) {
                $repositoryReference = $this->repositoryClassReference($source, $php, $type->name);
                $fields = $this->fields($document->uri, $document->text, $source, $type->name, $php);
                $entity = new DoctrineEntity($type->name, $document->uri, $range, $repositoryReference?->className, $fields);
                $entities[] = $entity;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, $entity->className, null, $document->uri, $entity->range, true);
                foreach ($fields as $field) {
                    $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field->name, $entity->className, $document->uri, $field->range, true);
                }
                if (null !== $repositoryReference) {
                    $symbols[] = new DoctrineSourceSymbol(
                        DoctrineSymbolKind::Repository,
                        $repositoryReference->className,
                        null,
                        $document->uri,
                        $this->converter->toRange($document->text, $repositoryReference->startOffset, $repositoryReference->endOffset - $repositoryReference->startOffset),
                        false,
                    );
                }
            }
            $repository = $this->repository($document->uri, $document->text, $source, $type, $php);
            if (null !== $repository) {
                $repositories[] = $repository;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Repository, $repository->className, null, $document->uri, $repository->range, true);
                $entityReference = $this->repositoryEntityReference($source, $type, $php);
                if (null !== $entityReference) {
                    $symbols[] = new DoctrineSourceSymbol(
                        DoctrineSymbolKind::Entity,
                        $repository->entityClass,
                        null,
                        $document->uri,
                        $this->converter->toRange($document->text, $entityReference->startOffset, $entityReference->endOffset - $entityReference->startOffset),
                        false,
                    );
                }
            }
        }
        array_push($symbols, ...$this->formSymbols($document->uri, $document->text, $source, $php));
        array_push($symbols, ...$this->repositorySymbols($document->uri, $document->text, $source, $php, $repositories));

        return new DoctrineSourceFacts($document->uri, $entities, $repositories, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?DoctrineCompletionContext
    {
        if ('php' !== $languageId) {
            return null;
        }
        $php = $this->phpParser->parse($text);
        $source = $this->phpComments->mask($text);
        $before = substr($source, 0, $offset);
        if (preg_match('/[\'"](?:choice_label|choice_value|group_by)[\'"]\s*=>\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)$/s', $before, $field, \PREG_OFFSET_CAPTURE)
            && null !== $entityClass = $this->entityTypeClassAt($source, $php, $field[1][1])
        ) {
            return new DoctrineCompletionContext(
                DoctrineCompletionKind::EntityTypeField,
                $entityClass,
                null,
                $field[1][0],
                $this->converter->toRange($text, $field[1][1], \strlen($field[1][0])),
            );
        }

        return $this->repositoryCompletionContext($text, $source, $offset, $php);
    }

    /** @return list<DoctrineField> */
    private function fields(string $uri, string $text, string $source, string $className, PhpDocument $php): array
    {
        $fields = [];
        foreach ($php->propertyDeclarations as $property) {
            if ($className !== $property->className) {
                continue;
            }
            $attributes = $this->mappingAttributes(
                $php,
                PhpAttributeTargetKind::Property,
                $className,
                $property->name,
                ['Column', 'Embedded', 'Id', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'],
            );
            if ([] === $attributes) {
                continue;
            }
            $associationAttributes = array_values(array_filter(
                $attributes,
                static fn (PhpAttribute $attribute): bool => \in_array(substr($attribute->name, \strlen('Doctrine\\ORM\\Mapping\\')), self::ASSOCIATIONS, true),
            ));
            $type = [] === $property->types ? null : implode('|', $property->types);
            $fields[] = new DoctrineField(
                $property->name,
                $uri,
                $this->converter->toRange($text, $property->nameStartOffset, $property->nameEndOffset - $property->nameStartOffset),
                [] !== $associationAttributes,
                $type,
                $this->associationTarget($source, $associationAttributes, $property, $php),
            );
        }

        return $fields;
    }

    private function repositoryClassReference(string $source, PhpDocument $php, string $className): ?PhpClassReference
    {
        foreach ($this->mappingAttributes($php, PhpAttributeTargetKind::Type, $className, null, ['Entity']) as $attribute) {
            $reference = $this->directClassReference($source, $php, $attribute->argument('repositoryClass'));
            if (null !== $reference) {
                return $reference;
            }
        }

        return null;
    }

    private function repository(string $uri, string $text, string $source, PhpTypeDeclaration $type, PhpDocument $php): ?DoctrineRepository
    {
        if ('Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository' !== $type->parentClassName) {
            return null;
        }
        $entityClass = $this->repositoryEntityReference($source, $type, $php)?->className;
        if (null === $entityClass) {
            $before = substr($text, max(0, $type->startOffset - 1000), min(1000, $type->startOffset));
            if (preg_match('/@extends\s+(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?ServiceEntityRepository\s*<\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*>/', $before, $entity)) {
                $entityClass = $php->resolveName($entity[1]);
            }
        }
        if (null === $entityClass) {
            return null;
        }

        return new DoctrineRepository(
            $type->name,
            $entityClass,
            $uri,
            $this->converter->toRange($text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset),
        );
    }

    private function repositoryEntityReference(string $source, PhpTypeDeclaration $type, PhpDocument $php): ?PhpClassReference
    {
        foreach ($php->classReferences as $reference) {
            if ($reference->startOffset < $type->startOffset || $reference->endOffset > $type->endOffset) {
                continue;
            }
            $before = substr($source, $type->startOffset, $reference->startOffset - $type->startOffset);
            $boundary = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
            if (1 === preg_match('/\bparent\s*::\s*__construct\s*\([^,]+,\s*$/', substr($before, $boundary + 1))) {
                return $reference;
            }
        }

        return null;
    }

    private function entityTypeClassAt(string $source, PhpDocument $php, int $offset): ?string
    {
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['createForm', 'createNamed', 'add'], true)) {
                continue;
            }
            $typeIndex = 'createNamed' === $call->method ? 1 : ('add' === $call->method ? 1 : 0);
            $optionsIndex = 'createNamed' === $call->method ? 3 : 2;
            $options = $call->positionalArgument($optionsIndex);
            $start = $options?->expressionStartOffset;
            $end = $options?->expressionEndOffset;
            if (!\is_int($start) || !\is_int($end) || $offset < $start || $offset > $end) {
                continue;
            }
            $formType = $php->soleClassReference($call->positionalArgument($typeIndex));
            if ('Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' !== $formType?->className) {
                continue;
            }

            return $this->arrayClassReference($source, $php, $options, 'class')?->className;
        }

        return null;
    }

    /** @return list<DoctrineSourceSymbol> */
    private function formSymbols(string $uri, string $text, string $source, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['createForm', 'createNamed', 'add'], true)) {
                continue;
            }
            $typeIndex = 'createNamed' === $call->method ? 1 : ('add' === $call->method ? 1 : 0);
            $optionsIndex = 'createNamed' === $call->method ? 3 : 2;
            $formType = $php->soleClassReference($call->positionalArgument($typeIndex));
            $options = $call->positionalArgument($optionsIndex);
            if ('Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' !== $formType?->className || null === $options) {
                continue;
            }
            $entity = $this->arrayClassReference($source, $php, $options, 'class');
            if (null === $entity) {
                continue;
            }
            $entityClass = $entity->className;
            $symbols[] = new DoctrineSourceSymbol(
                DoctrineSymbolKind::Entity,
                $entityClass,
                null,
                $uri,
                $this->converter->toRange($text, $entity->startOffset, $entity->endOffset - $entity->startOffset),
                false,
            );
            foreach ($this->arrayKeys->parseArgument($options, allowNestedUnpacking: true, collectPartialLiteralKeys: true) ?? [] as $key) {
                if (!\in_array($key->value, ['choice_label', 'choice_value', 'group_by'], true)
                    || null === ($field = $this->literalArrayStringValue($source, $key))
                    || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field->value)
                ) {
                    continue;
                }
                $symbols[] = new DoctrineSourceSymbol(
                    DoctrineSymbolKind::Field,
                    $field->value,
                    $entityClass,
                    $uri,
                    $this->converter->toRange($text, $field->startOffset, $field->endOffset - $field->startOffset),
                    false,
                );
            }
        }

        return $symbols;
    }

    /**
     * @param list<DoctrineRepository> $localRepositories
     *
     * @return list<DoctrineSourceSymbol>
     */
    private function repositorySymbols(string $uri, string $text, string $source, PhpDocument $php, array $localRepositories): array
    {
        $localRepositoryClasses = [];
        foreach ($localRepositories as $repository) {
            $localRepositoryClasses[$repository->className] = true;
        }
        $receivers = $this->repositoryReceivers->resolveCalls($source, $php, $php->methodCalls, $localRepositoryClasses);
        $symbols = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['findBy', 'findOneBy', 'count'], true) || null === $call->positionalArgument(0)) {
                continue;
            }
            $receiver = $receivers[spl_object_id($call)] ?? null;
            $owner = $receiver['repositoryClass'] ?? $receiver['entityClass'] ?? null;
            if (null === $owner) {
                continue;
            }
            array_push($symbols, ...$this->criteriaSymbols($uri, $text, $call->positionalArgument(0), $owner));
        }

        return $symbols;
    }

    /** @return list<DoctrineSourceSymbol> */
    private function criteriaSymbols(string $uri, string $text, PhpArgument $argument, string $owner): array
    {
        $array = $argument->expression;
        $offset = $argument->expressionStartOffset;
        if (!\is_string($array) || !\is_int($offset) || !preg_match('/^\s*\[/', $array)) {
            return [];
        }
        $symbols = [];
        foreach ($this->arrayKeys->parseArgument($argument, allowNestedUnpacking: true, collectPartialLiteralKeys: true) ?? [] as $key) {
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key->value)) {
                continue;
            }
            $symbols[] = new DoctrineSourceSymbol(
                DoctrineSymbolKind::Field,
                $key->value,
                $owner,
                $uri,
                $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
                false,
            );
        }

        return $symbols;
    }

    private function repositoryCompletionContext(string $text, string $source, int $offset, PhpDocument $php): ?DoctrineCompletionContext
    {
        $before = substr($source, 0, $offset);
        if (!preg_match('/(?<receiver>(?:(?:\$this(?:\s*->\s*[A-Za-z_][A-Za-z0-9_]*)?|\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*getRepository\s*\(\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*::class\s*\)|\$this(?:\s*->\s*[A-Za-z_][A-Za-z0-9_]*)?|\$[A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:findBy|findOneBy|count)\s*\(\s*[^;]*(?:\[|,)\s*[\'"](?<prefix>[A-Za-z_][A-Za-z0-9_]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $call = null;
        foreach ($php->methodCalls as $candidate) {
            if ($match['receiver'][1] === $candidate->startOffset && \in_array($candidate->method, ['findBy', 'findOneBy', 'count'], true)) {
                $call = $candidate;
                break;
            }
        }
        if (null === $call) {
            return null;
        }
        $localRepositoryClasses = [];
        foreach ($php->typeDeclarations as $type) {
            if (!$type->isClass()) {
                continue;
            }
            $repository = $this->repository('', $text, $source, $type, $php);
            if (null !== $repository) {
                $localRepositoryClasses[$repository->className] = true;
            }
        }
        $receiver = $this->repositoryReceivers->resolveCall($source, $php, $call, $localRepositoryClasses);
        if (null === $receiver) {
            return null;
        }
        $prefix = $match['prefix'][0];

        return new DoctrineCompletionContext(
            DoctrineCompletionKind::RepositoryCriteria,
            $receiver['entityClass'],
            $receiver['repositoryClass'],
            $prefix,
            $this->converter->toRange($text, $match['prefix'][1], \strlen($prefix)),
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<PhpAttribute>
     */
    private function mappingAttributes(PhpDocument $php, PhpAttributeTargetKind $kind, string $className, ?string $memberName, array $names): array
    {
        $attributes = [];
        foreach ($php->attributesOn($kind, $className, $memberName) as $attribute) {
            if (str_starts_with($attribute->name, 'Doctrine\\ORM\\Mapping\\')
                && \in_array(substr($attribute->name, \strlen('Doctrine\\ORM\\Mapping\\')), $names, true)
            ) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    private function arrayClassReference(string $source, PhpDocument $php, PhpArgument $argument, string $key): ?PhpClassReference
    {
        $start = $argument->expressionStartOffset;
        $end = $argument->expressionEndOffset;
        if (!\is_int($start) || !\is_int($end)) {
            return null;
        }
        foreach ($this->arrayKeys->parseArgument($argument, allowNestedUnpacking: true, collectPartialLiteralKeys: true) ?? [] as $literalKey) {
            if ($key !== $literalKey->value) {
                continue;
            }
            foreach ($php->classReferences as $reference) {
                if ($reference->startOffset < $start || $reference->endOffset > $end || $reference->startOffset <= $literalKey->endOffset) {
                    continue;
                }
                if (1 === preg_match('/^\s*=>\s*$/', substr($source, $literalKey->endOffset + 1, $reference->startOffset - $literalKey->endOffset - 1))) {
                    return $reference;
                }
            }
        }

        return null;
    }

    private function literalArrayStringValue(string $source, PhpStringLiteral $key): ?PhpStringLiteral
    {
        $sourceOffset = $key->endOffset + 1;
        $prefix = '<?php ';
        $value = null;
        $afterArrow = false;
        foreach (\PhpToken::tokenize($prefix.substr($source, $sourceOffset)) as $token) {
            if ($token->is([\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            if (!$afterArrow) {
                if (\T_DOUBLE_ARROW !== $token->id) {
                    return null;
                }
                $afterArrow = true;

                continue;
            }
            if (null === $value) {
                if (\T_CONSTANT_ENCAPSED_STRING !== $token->id) {
                    return null;
                }
                $startOffset = $sourceOffset + $token->pos - \strlen($prefix) + 1;
                $value = new PhpStringLiteral(
                    PhpStringLiteralDecoder::decode($token->text[0], substr($token->text, 1, -1)),
                    $startOffset,
                    $startOffset + \strlen($token->text) - 2,
                );

                continue;
            }

            return \in_array($token->text, [',', ']'], true) ? $value : null;
        }

        return $value;
    }

    private function directClassReference(string $source, PhpDocument $php, ?PhpArgument $argument): ?PhpClassReference
    {
        $reference = $php->soleClassReference($argument);
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (null === $reference || !\is_int($start) || !\is_int($end)) {
            return null;
        }
        $before = trim(substr($source, $start, $reference->startOffset - $start));
        $after = substr($source, $reference->endOffset, $end - $reference->endOffset);

        return '' === $before && 1 === preg_match('/^\s*::\s*class\s*$/iD', $after) ? $reference : null;
    }

    /** @param list<PhpAttribute> $attributes */
    private function associationTarget(string $source, array $attributes, PhpPropertyDeclaration $property, PhpDocument $php): ?string
    {
        foreach ($attributes as $attribute) {
            $reference = $this->directClassReference($source, $php, $attribute->argument('targetEntity'));
            if (null !== $reference) {
                return $reference->className;
            }
        }
        if (1 !== \count($property->types)) {
            return null;
        }
        $type = $property->types[0];
        $separator = strrpos($type, '\\');
        $shortName = strtolower(false === $separator ? $type : substr($type, $separator + 1));

        return \in_array($shortName, ['array', 'collection', 'iterable', 'mixed'], true) ? null : $type;
    }

    /** @param list<DoctrineSourceSymbol> $symbols
     *
     * @return list<DoctrineSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = implode('|', [$symbol->kind->value, $symbol->owner, $symbol->name, $symbol->uri, serialize($symbol->range)]);
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
