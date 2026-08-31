<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Parser\Php\PhpDocument;

final class RouteControllerClassifier
{
    private const ABSTRACT_CONTROLLER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController';

    public function isController(?string $className, PhpDocument $document, ?DependencyInjectionSourceIndex $classIndex): bool
    {
        if (null === $className) {
            return true;
        }
        if (null !== $classIndex && [] !== $classIndex->classDeclarations($className)) {
            return $classIndex->isSubclassOf($className, self::ABSTRACT_CONTROLLER);
        }

        $types = [];
        foreach ($document->typeDeclarations as $type) {
            $types[strtolower(ltrim($type->name, '\\'))] = $type;
        }
        $visited = [];
        while (!isset($visited[strtolower($className)])) {
            $className = ltrim($className, '\\');
            if (0 === strcasecmp(self::ABSTRACT_CONTROLLER, $className)) {
                return true;
            }
            $classKey = strtolower($className);
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
