<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Parser\Php\PhpDocument;

final class RouteControllerClassifier
{
    private const ABSTRACT_CONTROLLER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController';

    public function isController(?string $className, ?PhpDocument $document, ?DependencyInjectionSourceIndex $classIndex): bool
    {
        if (null === $className) {
            return true;
        }
        if (null !== $classIndex && (null === $document || [] !== $classIndex->classDeclarations($className))) {
            return $classIndex->isSubclassOf($className, self::ABSTRACT_CONTROLLER);
        }
        if (null === $document) {
            return false;
        }

        $types = [];
        foreach ($document->typeDeclarations as $type) {
            $types[ClassNameKey::from($type->name)] = $type;
        }
        $visited = [];
        while (!isset($visited[ClassNameKey::from($className)])) {
            $className = ltrim($className, '\\');
            if (0 === strcasecmp(self::ABSTRACT_CONTROLLER, $className)) {
                return true;
            }
            $classKey = ClassNameKey::from($className);
            $type = $types[$classKey] ?? null;
            if (null === $type) {
                return 0 === strcasecmp('AbstractController', $className);
            }
            $visited[$classKey] = true;
            if (null === $className = $type->parentClassName) {
                return false;
            }
        }

        return false;
    }
}
