<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class DoctrineExtractor
{
    private const ASSOCIATIONS = ['Embedded', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'];

    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): DoctrineSourceFacts
    {
        if ('php' !== $languageId) {
            return new DoctrineSourceFacts($uri, [], [], []);
        }
        [$namespace, $imports] = $this->phpNames($text);
        $classes = $this->classes($text, $namespace, $imports);
        $entities = [];
        $repositories = [];
        $symbols = [];
        foreach ($classes as $class) {
            if ($this->hasMappingAttribute($class['attributes'], $imports, ['Entity', 'MappedSuperclass'])) {
                [$repositoryClass, $repositoryOffset] = $this->repositoryClass($class, $namespace, $imports);
                $fields = $this->fields($uri, $text, $class, $namespace, $imports);
                $entity = new DoctrineEntity($class['className'], $uri, $class['range'], $repositoryClass, $fields);
                $entities[] = $entity;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, $entity->className(), null, $uri, $entity->range(), true);
                foreach ($fields as $field) {
                    $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field->name(), $entity->className(), $uri, $field->range(), true);
                }
                if (null !== $repositoryClass && null !== $repositoryOffset) {
                    $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Repository, $repositoryClass, null, $uri, $this->offsetRange($text, $repositoryOffset, $this->shortNameLengthAt($text, $repositoryOffset)), false);
                }
            }
            $repository = $this->repository($uri, $text, $class, $namespace, $imports);
            if (null !== $repository) {
                $repositories[] = $repository;
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Repository, $repository->className(), null, $uri, $repository->range(), true);
                $entityOffset = $this->repositoryEntityOffset($class);
                if (null !== $entityOffset) {
                    $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, $repository->entityClass(), null, $uri, $this->offsetRange($text, $entityOffset, $this->shortNameLengthAt($text, $entityOffset)), false);
                }
            }
        }
        array_push($symbols, ...$this->formSymbols($uri, $text, $namespace, $imports));
        array_push($symbols, ...$this->repositorySymbols($uri, $text, $namespace, $imports, $repositories));

        return new DoctrineSourceFacts($uri, $entities, $repositories, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?DoctrineCompletionContext
    {
        if ('php' !== $languageId) {
            return null;
        }
        [$namespace, $imports] = $this->phpNames($text);
        $before = substr($text, 0, $offset);
        if (preg_match('/[\'"](?:choice_label|choice_value|group_by)[\'"]\s*=>\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)$/s', $before, $field, \PREG_OFFSET_CAPTURE)) {
            $statementStart = max((int) strrpos($before, ';'), (int) strrpos($before, '->add('), (int) strrpos($before, 'createForm('), (int) strrpos($before, 'createNamed('));
            $statement = substr($before, $statementStart);
            if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*,\s*\[[\s\S]*[\'"]class[\'"]\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $statement, $classes)
                && 'Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' === $this->resolvePhpName($classes[1], $namespace, $imports)) {
                return new DoctrineCompletionContext(
                    $this->resolvePhpName($classes[2], $namespace, $imports),
                    null,
                    $field[1][0],
                    $this->offsetRange($text, $field[1][1], \strlen($field[1][0])),
                );
            }
        }

        return $this->repositoryCompletionContext($text, $offset, $namespace, $imports);
    }

    /**
     * @param array{className: string, shortName: string, header: string, body: string, bodyOffset: int, attributes: string, attributesOffset: int, before: string, range: Range} $class
     * @param array<string, string>                                                                                                                                               $imports
     *
     * @return list<DoctrineField>
     */
    private function fields(string $uri, string $text, array $class, string $namespace, array $imports): array
    {
        preg_match_all(
            '/(?:(?:public|protected|private|var|readonly|static)\s+)+(?:(\??[A-Za-z_\\\\][A-Za-z0-9_\\\\|?]*)\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/',
            $class['body'],
            $properties,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $fields = [];
        foreach ($properties as $property) {
            $before = substr($class['body'], 0, $property[0][1]);
            $boundary = max((int) strrpos($before, ';'), (int) strrpos($before, '{'), (int) strrpos($before, '}'));
            $attributes = substr($before, $boundary + 1);
            if (!$this->hasMappingAttribute($attributes, $imports, ['Column', 'Embedded', 'Id', 'ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'])) {
                continue;
            }
            $association = $this->hasMappingAttribute($attributes, $imports, self::ASSOCIATIONS);
            $type = '' !== $property[1][0] ? ltrim($property[1][0], '?') : null;
            $targetEntity = $this->associationTarget($attributes, $type, $namespace, $imports);
            $offset = $class['bodyOffset'] + $property[2][1];
            $fields[] = new DoctrineField(
                $property[2][0],
                $uri,
                $this->offsetRange($text, $offset, \strlen($property[2][0])),
                $association,
                $type,
                $targetEntity,
            );
        }

        return $fields;
    }

    /**
     * @param array{className: string, shortName: string, header: string, body: string, bodyOffset: int, attributes: string, attributesOffset: int, before: string, range: Range} $class
     * @param array<string, string>                                                                                                                                               $imports
     *
     * @return array{?string, ?int}
     */
    private function repositoryClass(array $class, string $namespace, array $imports): array
    {
        foreach ($this->attributes($class['attributes'], $imports) as $attribute) {
            if ('Doctrine\\ORM\\Mapping\\Entity' !== $attribute['class'] || !preg_match('/\brepositoryClass\s*:\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $attribute['arguments'], $repository, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $offset = $class['attributesOffset'] + $attribute['argumentsOffset'] + $repository[1][1];

            return [$this->resolvePhpName($repository[1][0], $namespace, $imports), $offset];
        }

        return [null, null];
    }

    /**
     * @param array{className: string, shortName: string, header: string, body: string, bodyOffset: int, attributes: string, attributesOffset: int, before: string, range: Range} $class
     * @param array<string, string>                                                                                                                                               $imports
     */
    private function repository(string $uri, string $text, array $class, string $namespace, array $imports): ?DoctrineRepository
    {
        if (!preg_match('/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $class['header'], $parent)
            || 'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository' !== $this->resolvePhpName($parent[1], $namespace, $imports)) {
            return null;
        }
        $entityClass = null;
        if (preg_match('/\bparent\s*::\s*__construct\s*\([^,]+,\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $class['body'], $entity)) {
            $entityClass = $this->resolvePhpName($entity[1], $namespace, $imports);
        } elseif (preg_match('/@extends\s+(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?ServiceEntityRepository\s*<\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*>/', $class['before'], $entity)) {
            $entityClass = $this->resolvePhpName($entity[1], $namespace, $imports);
        }
        if (null === $entityClass) {
            return null;
        }

        return new DoctrineRepository($class['className'], $entityClass, $uri, $class['range']);
    }

    /**
     * @param array{body: string, bodyOffset: int} $class
     */
    private function repositoryEntityOffset(array $class): ?int
    {
        if (!preg_match('/\bparent\s*::\s*__construct\s*\([^,]+,\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $class['body'], $entity, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return $class['bodyOffset'] + $entity[1][1];
    }

    /**
     * @param array<string, string> $imports
     *
     * @return list<DoctrineSourceSymbol>
     */
    private function formSymbols(string $uri, string $text, string $namespace, array $imports): array
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*,\s*\[/', $text, $formTypes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($formTypes as $formType) {
            if ('Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType' !== $this->resolvePhpName($formType[1][0], $namespace, $imports)) {
                continue;
            }
            $open = $formType[0][1] + \strlen($formType[0][0]) - 1;
            $close = $this->matching($text, $open, '[', ']');
            if (null === $close) {
                continue;
            }
            $options = substr($text, $open + 1, $close - $open - 1);
            if (!preg_match('/[\'"]class[\'"]\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $options, $entity, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $entityClass = $this->resolvePhpName($entity[1][0], $namespace, $imports);
            $entityOffset = $open + 1 + $entity[1][1];
            $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Entity, $entityClass, null, $uri, $this->offsetRange($text, $entityOffset, \strlen($entity[1][0])), false);
            preg_match_all('/[\'"](?:choice_label|choice_value|group_by)[\'"]\s*=>\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', $options, $fields, \PREG_OFFSET_CAPTURE);
            foreach ($fields[2] as [$field, $fieldOffset]) {
                $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field, $entityClass, $uri, $this->offsetRange($text, $open + 1 + $fieldOffset, \strlen($field)), false);
            }
        }

        return $symbols;
    }

    /**
     * @param array<string, string>    $imports
     * @param list<DoctrineRepository> $localRepositories
     *
     * @return list<DoctrineSourceSymbol>
     */
    private function repositorySymbols(string $uri, string $text, string $namespace, array $imports, array $localRepositories): array
    {
        $repositoryVariables = $this->repositoryVariables($text, $namespace, $imports);
        $entityVariables = [];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*?->\s*getRepository\s*\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*\)/', $text, $assignments, \PREG_SET_ORDER);
        foreach ($assignments as $assignment) {
            $entityVariables[$assignment[1]] = $this->resolvePhpName($assignment[2], $namespace, $imports);
        }
        $symbols = [];
        preg_match_all('/(\$this(?:->([A-Za-z_][A-Za-z0-9_]*))?|\$([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:findBy|findOneBy|count)\s*\(\s*\[/', $text, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($calls as $call) {
            $owner = null;
            if ('$this' === $call[1][0] && isset($localRepositories[0])) {
                $owner = $localRepositories[0]->className();
            } else {
                $variable = '' !== ($call[2][0] ?? '') ? $call[2][0] : ($call[3][0] ?? '');
                $owner = $repositoryVariables[$variable] ?? $entityVariables[$variable] ?? null;
            }
            if (null === $owner) {
                continue;
            }
            $open = $call[0][1] + \strlen($call[0][0]) - 1;
            array_push($symbols, ...$this->criteriaSymbols($uri, $text, $open, $owner));
        }
        preg_match_all('/getRepository\s*\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*\)\s*->\s*(?:findBy|findOneBy|count)\s*\(\s*\[/', $text, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($calls as $call) {
            $owner = $this->resolvePhpName($call[1][0], $namespace, $imports);
            $open = $call[0][1] + \strlen($call[0][0]) - 1;
            array_push($symbols, ...$this->criteriaSymbols($uri, $text, $open, $owner));
        }

        return $symbols;
    }

    /** @return list<DoctrineSourceSymbol> */
    private function criteriaSymbols(string $uri, string $text, int $open, string $owner): array
    {
        $close = $this->matching($text, $open, '[', ']');
        if (null === $close) {
            return [];
        }
        $array = substr($text, $open + 1, $close - $open - 1);
        preg_match_all('/([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*=>/', $array, $keys, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($keys[2] as [$field, $offset]) {
            $symbols[] = new DoctrineSourceSymbol(DoctrineSymbolKind::Field, $field, $owner, $uri, $this->offsetRange($text, $open + 1 + $offset, \strlen($field)), false);
        }

        return $symbols;
    }

    /** @param array<string, string> $imports */
    private function repositoryCompletionContext(string $text, int $offset, string $namespace, array $imports): ?DoctrineCompletionContext
    {
        $before = substr($text, 0, $offset);
        if (!preg_match('/(?:\[|,)\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)$/s', $before, $prefix, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $repositoryVariables = $this->repositoryVariables($text, $namespace, $imports);
        $entityVariables = [];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*?->\s*getRepository\s*\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*\)/', $text, $assignments, \PREG_SET_ORDER);
        foreach ($assignments as $assignment) {
            $entityVariables[$assignment[1]] = $this->resolvePhpName($assignment[2], $namespace, $imports);
        }
        $localRepositories = [];
        foreach ($this->classes($text, $namespace, $imports) as $class) {
            $repository = $this->repository('', $text, $class, $namespace, $imports);
            if (null !== $repository) {
                $localRepositories[] = $repository;
            }
        }
        preg_match_all('/(\$this(?:->([A-Za-z_][A-Za-z0-9_]*))?|\$([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:findBy|findOneBy|count)\s*\(\s*\[/', $before, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $call = [] === $calls ? null : $calls[array_key_last($calls)];
        if (null !== $call) {
            $owner = null;
            $variable = '';
            if ('$this' === $call[1][0] && isset($localRepositories[0])) {
                $owner = $localRepositories[0]->className();
            } else {
                $variable = '' !== ($call[2][0] ?? '') ? $call[2][0] : ($call[3][0] ?? '');
                $owner = $repositoryVariables[$variable] ?? null;
            }
            if (null !== $owner) {
                return new DoctrineCompletionContext(null, $owner, $prefix[1][0], $this->offsetRange($text, $prefix[1][1], \strlen($prefix[1][0])));
            }
            if (isset($entityVariables[$variable])) {
                return new DoctrineCompletionContext($entityVariables[$variable], null, $prefix[1][0], $this->offsetRange($text, $prefix[1][1], \strlen($prefix[1][0])));
            }
        }
        preg_match_all('/getRepository\s*\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\s*\)\s*->\s*(?:findBy|findOneBy|count)\s*\(\s*\[/', $before, $calls, \PREG_SET_ORDER);
        if ([] !== $calls) {
            $call = $calls[array_key_last($calls)];

            return new DoctrineCompletionContext($this->resolvePhpName($call[1], $namespace, $imports), null, $prefix[1][0], $this->offsetRange($text, $prefix[1][1], \strlen($prefix[1][0])));
        }

        return null;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return array<string, string>
     */
    private function repositoryVariables(string $text, string $namespace, array $imports): array
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Repository)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $types, \PREG_SET_ORDER);
        $variables = [];
        foreach ($types as $type) {
            $variables[$type[2]] = $this->resolvePhpName($type[1], $namespace, $imports);
        }

        return $variables;
    }

    /** @param array<string, string> $imports */
    private function associationTarget(string $attributes, ?string $type, string $namespace, array $imports): ?string
    {
        if (preg_match('/\btargetEntity\s*:\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class/', $attributes, $target)) {
            return $this->resolvePhpName($target[1], $namespace, $imports);
        }
        if (null === $type || \in_array(strtolower($type), ['array', 'collection', 'iterable', 'mixed'], true)) {
            return null;
        }

        return $this->resolvePhpName($type, $namespace, $imports);
    }

    /**
     * @param array<string, string> $imports
     * @param list<string>          $names
     */
    private function hasMappingAttribute(string $text, array $imports, array $names): bool
    {
        foreach ($this->attributes($text, $imports) as $attribute) {
            if (str_starts_with($attribute['class'], 'Doctrine\\ORM\\Mapping\\') && \in_array(substr($attribute['class'], \strlen('Doctrine\\ORM\\Mapping\\')), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return list<array{class: string, arguments: string, argumentsOffset: int}>
     */
    private function attributes(string $text, array $imports): array
    {
        preg_match_all('/#\[\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s*\((.*?)\))?\s*]/s', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[] = [
                'class' => $this->resolvePhpName((string) $match[1][0], '', $imports),
                'arguments' => \is_string($match[2][0] ?? null) ? $match[2][0] : '',
                'argumentsOffset' => $match[2][1] >= 0 ? $match[2][1] : 0,
            ];
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return list<array{className: string, shortName: string, header: string, body: string, bodyOffset: int, attributes: string, attributesOffset: int, before: string, range: Range}>
     */
    private function classes(string $text, string $namespace, array $imports): array
    {
        preg_match_all('/(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)[^\{]*\{/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $classes = [];
        foreach ($matches as $match) {
            $open = $match[0][1] + \strlen($match[0][0]) - 1;
            $close = $this->matching($text, $open, '{', '}') ?? \strlen($text);
            $before = substr($text, 0, $match[0][1]);
            $boundary = max((int) strrpos($before, ';'), (int) strrpos($before, '}'));
            $attributeText = substr($before, $boundary + 1);
            $attributeOffset = $boundary + 1;
            $shortName = $match[1][0];
            $classes[] = [
                'className' => '' === $namespace ? $shortName : $namespace.'\\'.$shortName,
                'shortName' => $shortName,
                'header' => $match[0][0],
                'body' => substr($text, $open + 1, $close - $open - 1),
                'bodyOffset' => $open + 1,
                'attributes' => $attributeText,
                'attributesOffset' => $attributeOffset,
                'before' => substr($before, max(0, \strlen($before) - 1000)),
                'range' => $this->offsetRange($text, $match[1][1], \strlen($shortName)),
            ];
        }

        return $classes;
    }

    /** @return array{string, array<string, string>} */
    private function phpNames(string $text): array
    {
        preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $namespace);
        $namespace = isset($namespace[1]) ? trim($namespace[1]) : '';
        preg_match_all('/^\s*use\s+(?!function\s|const\s)([^;]+);/m', $text, $uses);
        $imports = [];
        foreach ($uses[1] as $use) {
            $use = trim($use);
            if (str_contains($use, ',')) {
                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $use, $alias)) {
                $imports[$alias[2]] = ltrim(trim($alias[1]), '\\');
            } else {
                $imports[substr($use, (int) strrpos($use, '\\') + 1)] = ltrim($use, '\\');
            }
        }

        return [$namespace, $imports];
    }

    /** @param array<string, string> $imports */
    private function resolvePhpName(string $name, string $namespace, array $imports): string
    {
        $name = ltrim($name, '\\');
        $separator = strpos($name, '\\');
        $head = false === $separator ? $name : substr($name, 0, $separator);
        if (isset($imports[$head])) {
            return $imports[$head].(false === $separator ? '' : substr($name, $separator));
        }

        return false !== $separator || '' === $namespace ? $name : $namespace.'\\'.$name;
    }

    private function matching(string $text, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($index = $open, $length = \strlen($text); $index < $length; ++$index) {
            $character = $text[$index];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if ('\'' === $character || '"' === $character) {
                $quote = $character;
            } elseif ($opening === $character) {
                ++$depth;
            } elseif ($closing === $character && 0 === --$depth) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<DoctrineSourceSymbol> $symbols
     *
     * @return list<DoctrineSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = implode('|', [$symbol->kind()->value, $symbol->owner(), $symbol->name(), $symbol->uri(), serialize($symbol->range())]);
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }

    private function shortNameLengthAt(string $text, int $offset): int
    {
        return preg_match('/[A-Za-z_\\\\][A-Za-z0-9_\\\\]*/A', substr($text, $offset), $name) ? \strlen($name[0]) : 0;
    }

    private function offsetRange(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
