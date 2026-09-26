<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceSymbols;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpArgumentCursor;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpPropertyDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class DoctrineExtractor
{
    private const ASSOCIATIONS = ['Embedded', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'];
    private const FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParser $phpComments,
        private readonly DoctrineRepositoryReceiverResolver $repositoryReceivers,
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
                $repositoryReference = $this->repositoryClassReference($php, $type->name);
                $fields = $this->fields($document->uri, $document->text, $type->name, $php);
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
        array_push($symbols, ...$this->formSymbols($document->uri, $document->text, $php));
        array_push($symbols, ...$this->repositorySymbols($document->uri, $document->text, $source, $php, $repositories));

        return new DoctrineSourceFacts($document->uri, $entities, $repositories, SourceSymbols::unique(
            $symbols,
            static fn (DoctrineSourceSymbol $symbol): string => $symbol->kind->value."\0".$symbol->owner."\0".$symbol->name,
        ));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?DoctrineCompletionContext
    {
        if ('php' !== $languageId) {
            return null;
        }
        $php = $this->phpParser->parse($text);
        $cursor = $php->argumentCursorAt($offset);
        $call = $cursor?->call;
        if (null === $cursor || null === $cursor->quote || !$call instanceof PhpMethodCall || 1 !== preg_match(self::FIELD_PATTERN, $cursor->prefix)) {
            return null;
        }
        $source = $this->phpComments->mask($text);

        return $this->entityTypeFieldContext($text, $php, $cursor, $call)
            ?? $this->repositoryCriteriaContext($text, $source, $php, $cursor, $call);
    }

    private function entityTypeFieldContext(string $text, PhpDocument $php, PhpArgumentCursor $cursor, PhpMethodCall $call): ?DoctrineCompletionContext
    {
        $options = $this->formOptionsArgument($call);
        if (null === $options
            || $cursor->argument !== $options
            || !\in_array($this->arrayItemKey($php, $options, $cursor), ['choice_label', 'choice_value', 'group_by'], true)
            || 'Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' !== $call->positionalArgument($this->formTypeIndex($call))?->completeClassReference?->className
            || null === $entityClass = $this->arrayClassReference($php, $options, 'class')?->className
        ) {
            return null;
        }

        return new DoctrineCompletionContext(
            DoctrineCompletionKind::EntityTypeField,
            $entityClass,
            null,
            $cursor->prefix,
            $this->converter->toRange($text, $cursor->prefixStartOffset, \strlen($cursor->prefix)),
        );
    }

    private function arrayItemKey(PhpDocument $php, PhpArgument $argument, PhpArgumentCursor $cursor): ?string
    {
        foreach ($php->literalArray($argument)->entries ?? [] as $entry) {
            if ($entry->valueStartOffset === $cursor->prefixStartOffset - 1) {
                return $entry->key?->value;
            }
        }

        return null;
    }

    /** @return list<DoctrineField> */
    private function fields(string $uri, string $text, string $className, PhpDocument $php): array
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
                $this->associationTarget($associationAttributes, $property),
            );
        }

        return $fields;
    }

    private function repositoryClassReference(PhpDocument $php, string $className): ?PhpClassReference
    {
        foreach ($this->mappingAttributes($php, PhpAttributeTargetKind::Type, $className, null, ['Entity']) as $attribute) {
            $reference = $attribute->argument('repositoryClass')?->completeClassReference;
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
        $entityClass = $this->repositoryEntityReference($source, $type, $php)->className
            ?? $this->documentedEntityClass($text, $source, $type, $php);
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

    /**
     * The entity named by an `@extends ServiceEntityRepository<Entity>` tag in
     * the doc comment attached to $type, which is the last comment before it
     * with no statement boundary in between.
     */
    private function documentedEntityClass(string $text, string $source, PhpTypeDeclaration $type, PhpDocument $php): ?string
    {
        $attached = null;
        foreach ($this->phpComments->comments($text) as $comment) {
            if ($comment->endOffset <= $type->startOffset
                && !preg_match('/[;{}]/', substr($source, $comment->endOffset, $type->startOffset - $comment->endOffset))
            ) {
                $attached = $comment;
            }
        }
        if (null === $attached
            || !preg_match('/@extends\s+(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?ServiceEntityRepository\s*<\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*>/', $attached->content, $entity)
        ) {
            return null;
        }

        return $php->resolveName($entity[1]);
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

    private function formTypeIndex(PhpMethodCall $call): int
    {
        return 'createForm' === $call->method ? 0 : 1;
    }

    private function formOptionsArgument(PhpMethodCall $call): ?PhpArgument
    {
        return \in_array($call->method, ['createForm', 'createNamed', 'add'], true)
            ? $call->positionalArgument('createNamed' === $call->method ? 3 : 2)
            : null;
    }

    /** @return list<DoctrineSourceSymbol> */
    private function formSymbols(string $uri, string $text, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($php->methodCalls as $call) {
            $options = $this->formOptionsArgument($call);
            if (null === $options || 'Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' !== $call->positionalArgument($this->formTypeIndex($call))?->completeClassReference?->className) {
                continue;
            }
            $entity = $this->arrayClassReference($php, $options, 'class');
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
            foreach ($php->literalArray($options)->entries ?? [] as $entry) {
                $field = $entry->stringValue;
                if (!\in_array($entry->key?->value, ['choice_label', 'choice_value', 'group_by'], true)
                    || null === $field
                    || 1 !== preg_match(self::FIELD_PATTERN, $field->value)
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
            $criteria = $this->criteriaArgument($call);
            if (null === $criteria) {
                continue;
            }
            $receiver = $receivers[spl_object_id($call)] ?? null;
            $owner = $receiver['repositoryClass'] ?? $receiver['entityClass'] ?? null;
            if (null === $owner) {
                continue;
            }
            array_push($symbols, ...$this->criteriaSymbols($uri, $text, $php, $criteria, $owner));
        }

        return $symbols;
    }

    private function criteriaArgument(PhpMethodCall $call): ?PhpArgument
    {
        return \in_array($call->method, ['findBy', 'findOneBy', 'count'], true) ? $call->namedOrPositionalArgument('criteria', 0) : null;
    }

    /** @return list<DoctrineSourceSymbol> */
    private function criteriaSymbols(string $uri, string $text, PhpDocument $php, PhpArgument $argument, string $owner): array
    {
        $symbols = [];
        foreach ($php->literalArray($argument)->keys ?? [] as $key) {
            if (1 !== preg_match(self::FIELD_PATTERN, $key->value)) {
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

    private function repositoryCriteriaContext(string $text, string $source, PhpDocument $php, PhpArgumentCursor $cursor, PhpMethodCall $call): ?DoctrineCompletionContext
    {
        if (!$cursor->isArrayItemLiteral() || $cursor->argument !== $this->criteriaArgument($call)) {
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

        return new DoctrineCompletionContext(
            DoctrineCompletionKind::RepositoryCriteria,
            $receiver['entityClass'],
            $receiver['repositoryClass'],
            $cursor->prefix,
            $this->converter->toRange($text, $cursor->prefixStartOffset, \strlen($cursor->prefix)),
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

    private function arrayClassReference(PhpDocument $php, PhpArgument $argument, string $key): ?PhpClassReference
    {
        foreach ($php->literalArray($argument)->entries ?? [] as $entry) {
            if ($key === $entry->key?->value && null !== $entry->classReference) {
                return $entry->classReference;
            }
        }

        return null;
    }

    /** @param list<PhpAttribute> $attributes */
    private function associationTarget(array $attributes, PhpPropertyDeclaration $property): ?string
    {
        if ([] === $attributes) {
            return null;
        }
        foreach ($attributes as $attribute) {
            $reference = $attribute->argument('targetEntity')?->completeClassReference;
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
}
