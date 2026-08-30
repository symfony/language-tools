<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpPropertyDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class DoctrineExtractor
{
    private const ASSOCIATIONS = ['Embedded', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly DoctrineRepositoryReceiverResolver $repositoryReceivers,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): DoctrineSourceFacts
    {
        if ('php' !== $languageId) {
            return new DoctrineSourceFacts($uri, [], [], []);
        }
        $php = $this->phpParser->parse($text);
        $source = $this->phpComments->mask($text);
        $entities = [];
        $repositories = [];
        $symbols = [];
        foreach ($php->typeDeclarations as $type) {
            if (!$type->isClass()) {
                continue;
            }
            $range = $this->converter->toRange($text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset);
            if ([] !== $this->mappingAttributes($php, PhpAttributeTargetKind::Type, $type->name, null, ['Entity', 'MappedSuperclass'])) {
                $repositoryReference = $this->repositoryClassReference($php, $type->name);
                $fields = $this->fields($uri, $text, $type->name, $php);
                $entity = new DoctrineEntity($type->name, $uri, $range, $repositoryReference?->className, $fields);
                $entities[] = $entity;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, $entity->className, null, $uri, $entity->range, true);
                foreach ($fields as $field) {
                    $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field->name, $entity->className, $uri, $field->range, true);
                }
                if (null !== $repositoryReference) {
                    $symbols[] = new DoctrineSourceSymbol(
                        DoctrineSymbolKind::Repository,
                        $repositoryReference->className,
                        null,
                        $uri,
                        $this->converter->toRange($text, $repositoryReference->startOffset, $repositoryReference->endOffset - $repositoryReference->startOffset),
                        false,
                    );
                }
            }
            $repository = $this->repository($uri, $text, $source, $type, $php);
            if (null !== $repository) {
                $repositories[] = $repository;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Repository, $repository->className, null, $uri, $repository->range, true);
                $entityReference = $this->repositoryEntityReference($source, $type, $php);
                if (null !== $entityReference) {
                    $symbols[] = new DoctrineSourceSymbol(
                        DoctrineSymbolKind::Entity,
                        $repository->entityClass,
                        null,
                        $uri,
                        $this->converter->toRange($text, $entityReference->startOffset, $entityReference->endOffset - $entityReference->startOffset),
                        false,
                    );
                }
            }
        }
        array_push($symbols, ...$this->formSymbols($uri, $text, $source, $php));
        array_push($symbols, ...$this->repositorySymbols($uri, $text, $source, $php, $repositories));

        return new DoctrineSourceFacts($uri, $entities, $repositories, $this->unique($symbols));
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
                $this->associationTarget($associationAttributes, $property, $php),
            );
        }

        return $fields;
    }

    private function repositoryClassReference(PhpDocument $php, string $className): ?PhpClassReference
    {
        foreach ($this->mappingAttributes($php, PhpAttributeTargetKind::Type, $className, null, ['Entity']) as $attribute) {
            $reference = $php->soleClassReference($attribute->argument('repositoryClass'));
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
            $expression = $options->expression;
            $offset = $options->expressionStartOffset;
            if (null === $entity || !\is_string($expression) || !\is_int($offset)) {
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
            preg_match_all('/[\'"](?:choice_label|choice_value|group_by)[\'"]\s*=>\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', $expression, $fields, \PREG_OFFSET_CAPTURE);
            foreach ($fields[2] as [$field, $fieldOffset]) {
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field, $entityClass, $uri, $this->converter->toRange($text, $offset + $fieldOffset, \strlen($field)), false);
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
        preg_match_all('/([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*=>/', $array, $keys, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($keys[2] as [$field, $fieldOffset]) {
            $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field, $owner, $uri, $this->converter->toRange($text, $offset + $fieldOffset, \strlen($field)), false);
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
        foreach ($php->classReferences as $reference) {
            if ($reference->startOffset < $start || $reference->endOffset > $end) {
                continue;
            }
            $before = substr($source, $start, $reference->startOffset - $start);
            $boundary = max((int) strrpos($before, ','), (int) strrpos($before, '['));
            if (1 === preg_match('/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>\s*$/', substr($before, $boundary + 1))) {
                return $reference;
            }
        }

        return null;
    }

    /** @param list<PhpAttribute> $attributes */
    private function associationTarget(array $attributes, PhpPropertyDeclaration $property, PhpDocument $php): ?string
    {
        foreach ($attributes as $attribute) {
            $reference = $php->soleClassReference($attribute->argument('targetEntity'));
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
