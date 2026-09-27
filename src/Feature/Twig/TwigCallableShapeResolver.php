<?php

namespace Symfony\Lsp\Feature\Twig;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Printer\Printer;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;

final class TwigCallableShapeResolver
{
    private const MAX_DEPTH = 32;
    private const SCOPE_CACHE_SIZE = 32;

    /** @var array<string, TwigCallableShapeScope> */
    private array $scopes = [];

    private readonly Printer $printer;

    public function __construct(
        private readonly TwigCallableMethodResolver $methods,
        private readonly TwigCallablePhpDocParser $phpDoc,
        private readonly PhpParserInterface $phpParser,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly ProjectDocumentReader $reader,
    ) {
        $this->printer = new Printer();
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     *
     * @return list<TwigCallableShapeKey>
     */
    public function keys(Project $project, TwigCallableHashArgument $argument, array $declarations): array
    {
        $keys = [];
        foreach ($this->navigate($project, $argument, $declarations, $argument->path) as [$type, $scope]) {
            foreach ($this->shapes($project, $type, $scope, 0) as [$shape]) {
                foreach ($shape->items as $item) {
                    $name = $this->keyName($item);
                    if (null !== $name && !isset($keys[$name])) {
                        $keys[$name] = new TwigCallableShapeKey($name, $this->printer->print($item->valueType), $item->optional);
                    }
                }
            }
        }

        return array_values($keys);
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     *
     * @return list<string>
     */
    public function values(Project $project, TwigCallableHashArgument $argument, array $declarations): array
    {
        if (null === $argument->key) {
            return [];
        }
        $values = [];
        foreach ($this->navigate($project, $argument, $declarations, [...$argument->path, $argument->key]) as [$type, $scope]) {
            foreach ($this->literals($project, $type, $scope, 0) as $value) {
                $values[$value] = true;
            }
        }

        return array_map(strval(...), array_keys($values));
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     * @param list<string>                  $path
     *
     * @return list<array{TypeNode, TwigCallableShapeScope}>
     */
    private function navigate(Project $project, TwigCallableHashArgument $argument, array $declarations, array $path): array
    {
        $current = $this->parameterType($project, $argument, $declarations);
        if (null === $current) {
            return [];
        }
        $current = [$current];
        foreach ($path as $key) {
            $next = [];
            foreach ($current as [$type, $scope]) {
                foreach ($this->shapes($project, $type, $scope, 0) as [$shape, $shapeScope]) {
                    foreach ($shape->items as $item) {
                        if ($key === $this->keyName($item)) {
                            $next[] = [$item->valueType, $shapeScope];
                        }
                    }
                }
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     *
     * @return array{TypeNode, TwigCallableShapeScope}|null
     */
    private function parameterType(Project $project, TwigCallableHashArgument $argument, array $declarations): ?array
    {
        $parameters = $this->methods->parameters($project, [
            'callable' => ['kind' => $argument->kind, 'declarations' => $declarations],
        ])['callable'] ?? null;
        if (null === $parameters) {
            return null;
        }
        $name = null === $argument->name ? ($parameters->nameable[$argument->position ?? -1] ?? null) : $argument->name;
        if (null === $name || !\in_array($name, $parameters->nameable, true)) {
            return null;
        }
        foreach ($declarations as $declaration) {
            foreach ($this->methods->resolve($project, [$declaration]) as $method) {
                if (null === $method->declaration->docComment) {
                    return null;
                }
                $document = $this->phpParser->parse($method->source);
                $scope = $this->scope($method->uri, $method->source, $document, $method->declaration->className) ?? new TwigCallableShapeScope($method->uri);
                $tags = $this->phpDoc->parse($method->declaration->docComment);
                foreach (['@phpstan-param', '@psalm-param', '@param'] as $tagName) {
                    foreach ($tags->getParamTagValues($tagName) as $tag) {
                        if ('$'.$name === $tag->parameterName) {
                            return [$tag->type, $scope];
                        }
                    }
                }

                return null;
            }
        }

        return null;
    }

    /** @return list<array{ArrayShapeNode, TwigCallableShapeScope}> */
    private function shapes(Project $project, TypeNode $type, TwigCallableShapeScope $scope, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        if ($type instanceof ArrayShapeNode) {
            return \in_array($type->kind, [ArrayShapeNode::KIND_ARRAY, ArrayShapeNode::KIND_NON_EMPTY_ARRAY], true) ? [[$type, $scope]] : [];
        }
        if ($type instanceof NullableTypeNode) {
            return $this->shapes($project, $type->type, $scope, $depth + 1);
        }
        if ($type instanceof UnionTypeNode || $type instanceof IntersectionTypeNode) {
            $shapes = [];
            foreach ($type->types as $member) {
                $shapes = [...$shapes, ...$this->shapes($project, $member, $scope, $depth + 1)];
            }

            return $shapes;
        }
        if ($type instanceof IdentifierTypeNode && null !== $alias = $this->alias($project, $type->name, $scope, $depth)) {
            return $this->shapes($project, $alias[0], $alias[1], $depth + 1);
        }

        return [];
    }

    /** @return list<string> */
    private function literals(Project $project, TypeNode $type, TwigCallableShapeScope $scope, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        if ($type instanceof ConstTypeNode) {
            return $type->constExpr instanceof ConstExprStringNode ? [$type->constExpr->value] : [];
        }
        if ($type instanceof NullableTypeNode) {
            return $this->literals($project, $type->type, $scope, $depth + 1);
        }
        if ($type instanceof UnionTypeNode) {
            $values = [];
            foreach ($type->types as $member) {
                $values = [...$values, ...$this->literals($project, $member, $scope, $depth + 1)];
            }

            return $values;
        }
        if ($type instanceof IdentifierTypeNode && null !== $alias = $this->alias($project, $type->name, $scope, $depth)) {
            return $this->literals($project, $alias[0], $alias[1], $depth + 1);
        }

        return [];
    }

    /** @return array{TypeNode, TwigCallableShapeScope}|null */
    private function alias(Project $project, string $name, TwigCallableShapeScope $scope, int $depth): ?array
    {
        if (isset($scope->aliases[$name])) {
            return [$scope->aliases[$name], $scope];
        }
        if (!isset($scope->imports[$name]) || $depth > self::MAX_DEPTH) {
            return null;
        }
        [$className, $importedAlias] = $scope->imports[$name];
        $target = $this->classScope($project, $className, $scope->uri);

        return null === $target ? null : $this->alias($project, $importedAlias, $target, $depth + 1);
    }

    private function classScope(Project $project, string $className, string $fromUri): ?TwigCallableShapeScope
    {
        $uris = [$fromUri];
        foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $class) {
            $uris[] = $class->uri;
        }
        foreach (array_unique($uris) as $uri) {
            $source = $this->reader->read($project, $uri)?->text;
            if (null === $source) {
                continue;
            }
            $scope = $this->scope($uri, $source, null, $className);
            if (null !== $scope) {
                return $scope;
            }
        }

        return null;
    }

    private function scope(string $uri, string $source, ?PhpDocument $document, string $className): ?TwigCallableShapeScope
    {
        $key = hash('xxh128', $source)."\0".strtolower($className);
        if (isset($this->scopes[$key])) {
            return $this->scopes[$key];
        }
        $document ??= $this->phpParser->parse($source);
        $type = array_find(
            $document->typeDeclarations,
            static fn ($type): bool => 0 === strcasecmp($type->name, $className),
        );
        if (null === $type) {
            return null;
        }
        $aliases = [];
        $imports = [];
        if (null !== $type->docComment) {
            $tags = $this->phpDoc->parse($type->docComment);
            foreach (['@phpstan-type', '@psalm-type'] as $tagName) {
                foreach ($tags->getTypeAliasTagValues($tagName) as $tag) {
                    $aliases[$tag->alias] ??= $tag->type;
                }
            }
            foreach (['@phpstan-import-type', '@psalm-import-type'] as $tagName) {
                foreach ($tags->getTypeAliasImportTagValues($tagName) as $tag) {
                    $imports[$tag->importedAs ?? $tag->importedAlias] ??= [
                        ltrim($document->resolveName($tag->importedFrom->name), '\\'),
                        $tag->importedAlias,
                    ];
                }
            }
        }
        if (\count($this->scopes) >= self::SCOPE_CACHE_SIZE) {
            unset($this->scopes[array_key_first($this->scopes)]);
        }

        return $this->scopes[$key] = new TwigCallableShapeScope($uri, $aliases, $imports);
    }

    private function keyName(ArrayShapeItemNode $item): ?string
    {
        return match (true) {
            $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
            $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
            default => null,
        };
    }
}
