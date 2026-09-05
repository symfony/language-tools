<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;

final class TemplatePhpReferenceResolver
{
    private const ABSTRACT_CONTROLLER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController';
    private const CONTROLLER_HELPER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\ControllerHelper';
    private const TWIG_ENVIRONMENT = 'Twig\\Environment';

    /**
     * @return array{
     *     className: string,
     *     requiredParentClassNames: list<string>,
     *     templateArgumentName: string,
     *     variablesArgumentName: string,
     * }|null
     */
    public function receiver(PhpDocument $document, PhpMethodCall $call): ?array
    {
        if (PhpMethodReceiverKind::This === $call->receiverContext->kind) {
            return null === $call->className ? null : [
                'className' => $call->className,
                'requiredParentClassNames' => [self::ABSTRACT_CONTROLLER],
                'templateArgumentName' => 'view',
                'variablesArgumentName' => 'parameters',
            ];
        }

        $types = [];
        foreach ($document->receiverVariables($call) as $variable) {
            if (1 !== \count($variable->types)) {
                return null;
            }
            $types[strtolower(ltrim($variable->types[0], '\\'))] = $variable->types[0];
        }
        if (1 !== \count($types)) {
            return null;
        }
        $className = array_values($types)[0];
        if ('renderView' === $call->method) {
            return [
                'className' => $className,
                'requiredParentClassNames' => [self::CONTROLLER_HELPER],
                'templateArgumentName' => 'view',
                'variablesArgumentName' => 'parameters',
            ];
        }
        if ('render' !== $call->method) {
            return null;
        }

        $argumentNames = array_values(array_filter(array_map(static fn ($argument): ?string => $argument->name, $call->arguments)));
        if ([] !== array_intersect($argumentNames, ['view', 'parameters'])) {
            return [
                'className' => $className,
                'requiredParentClassNames' => [self::CONTROLLER_HELPER],
                'templateArgumentName' => 'view',
                'variablesArgumentName' => 'parameters',
            ];
        }
        if ([] !== array_intersect($argumentNames, ['name', 'context'])) {
            return [
                'className' => $className,
                'requiredParentClassNames' => [self::TWIG_ENVIRONMENT],
                'templateArgumentName' => 'name',
                'variablesArgumentName' => 'context',
            ];
        }

        return [
            'className' => $className,
            'requiredParentClassNames' => [self::TWIG_ENVIRONMENT, self::CONTROLLER_HELPER],
            'templateArgumentName' => 'view',
            'variablesArgumentName' => 'parameters',
        ];
    }

    public static function supports(TemplateReference $reference, ?DependencyInjectionSourceIndex $classes = null, ?PhpDocument $document = null): bool
    {
        if (null === $reference->receiverClassName || [] === $reference->requiredParentClassNames) {
            return true;
        }

        return self::supportsReceiver($reference->receiverClassName, $reference->requiredParentClassNames, $classes, $document);
    }

    /** @param list<string> $parentClassNames */
    public static function supportsReceiver(string $className, array $parentClassNames, ?DependencyInjectionSourceIndex $classes = null, ?PhpDocument $document = null): bool
    {
        return array_any($parentClassNames, static fn (string $parentClassName): bool => self::isSubclassOf($className, $parentClassName, $classes, $document));
    }

    private static function isSubclassOf(string $className, string $parentClassName, ?DependencyInjectionSourceIndex $classes, ?PhpDocument $document): bool
    {
        $types = [];
        foreach (null === $document ? [] : $document->typeDeclarations as $type) {
            $types[strtolower(ltrim($type->name, '\\'))] = $type;
        }
        $visited = [];
        while (true) {
            $className = ltrim($className, '\\');
            if (0 === strcasecmp($className, $parentClassName)) {
                return true;
            }
            $key = strtolower($className);
            if (isset($visited[$key])) {
                return false;
            }
            $visited[$key] = true;
            $type = $types[$key] ?? null;
            if (null === $type) {
                return $classes?->isSubclassOf($className, $parentClassName) ?? false;
            }
            if (null === $className = $type->parentClassName) {
                return false;
            }
        }
    }
}
