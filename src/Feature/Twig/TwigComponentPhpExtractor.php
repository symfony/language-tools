<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTarget;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpPropertyDeclaration;

final class TwigComponentPhpExtractor
{
    private const AS_LIVE_COMPONENT = 'Symfony\\UX\\LiveComponent\\Attribute\\AsLiveComponent';
    private const AS_TWIG_COMPONENT = 'Symfony\\UX\\TwigComponent\\Attribute\\AsTwigComponent';
    private const LIVE_ACTION = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveAction';
    private const LIVE_LISTENER = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveListener';
    private const LIVE_PROP = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveProp';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigComponentNameResolver $names,
    ) {
    }

    public function extract(string $uri, string $text, PhpDocument $php): TwigComponentSourceFacts
    {
        $classesByName = [];
        foreach ($php->typeDeclarations as $type) {
            if ($type->isClass()) {
                $classesByName[$type->name] = $type;
            }
        }

        $propertiesByClass = [];
        foreach ($php->propertyDeclarations as $property) {
            $propertiesByClass[$property->className][] = $property;
        }

        $methodsByClass = [];
        foreach ($php->methodDeclarations as $method) {
            $methodsByClass[$method->className][] = $method;
        }

        $methodCallsByClass = [];
        foreach ($php->methodCalls as $call) {
            if (null !== $call->className) {
                $methodCallsByClass[$call->className][] = $call;
            }
        }

        $attributesByTarget = [];
        foreach ($php->attributes as $attribute) {
            foreach ($attribute->targets as $target) {
                $attributesByTarget[$this->targetKey($target->kind, $target->className, $target->memberName)][] = $attribute;
            }
        }

        $components = [];
        $events = [];
        foreach ($php->attributes as $attribute) {
            if (!\in_array($attribute->name, [self::AS_TWIG_COMPONENT, self::AS_LIVE_COMPONENT], true)) {
                continue;
            }
            $target = $this->target($attribute, PhpAttributeTargetKind::Type);
            if (null === $target || !isset($classesByName[$target->className])) {
                continue;
            }

            $className = $target->className;
            $template = $attribute->argument('template')?->stringLiteral?->value;
            $explicitName = ($attribute->argument('name') ?? $attribute->positionalArgument(0))?->stringLiteral?->value;
            $name = $this->names->component($explicitName, $template, $className);
            $properties = $this->properties($propertiesByClass[$className] ?? [], $attributesByTarget);
            [$actions, $listenerEvents] = $this->actions($methodsByClass[$className] ?? [], $attributesByTarget, $name, $uri, $text);
            $events = [...$events, ...$listenerEvents];
            $live = self::AS_LIVE_COMPONENT === $attribute->name;
            $components[] = new TwigComponent(
                $name,
                $uri,
                $this->converter->toRange($text, $target->nameStartOffset, $target->nameEndOffset - $target->nameStartOffset),
                $className,
                $template,
                $properties,
                $live,
                $actions,
            );
            if ($live) {
                $events = [...$events, ...$this->emittedEvents($methodCallsByClass[$className] ?? [], $name, $uri, $text)];
            }
        }

        return new TwigComponentSourceFacts($uri, $components, [], events: $events);
    }

    /**
     * @param list<PhpPropertyDeclaration>      $properties
     * @param array<string, list<PhpAttribute>> $attributesByTarget
     *
     * @return list<string>
     */
    private function properties(array $properties, array $attributesByTarget): array
    {
        $names = [];
        foreach ($properties as $property) {
            $attributes = $attributesByTarget[$this->targetKey(PhpAttributeTargetKind::Property, $property->className, $property->name)] ?? [];
            if ($property->isPublic() || array_any($attributes, static fn (PhpAttribute $attribute): bool => self::LIVE_PROP === $attribute->name)) {
                $names[] = $property->name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param list<PhpMethodDeclaration>        $methods
     * @param array<string, list<PhpAttribute>> $attributesByTarget
     *
     * @return array{list<TwigComponentAction>, list<LiveComponentEvent>}
     */
    private function actions(array $methods, array $attributesByTarget, string $component, string $uri, string $text): array
    {
        $actions = [];
        $events = [];
        foreach ($methods as $method) {
            $action = false;
            $listeners = [];
            foreach ($attributesByTarget[$this->targetKey(PhpAttributeTargetKind::Method, $method->className, $method->name)] ?? [] as $attribute) {
                if (self::LIVE_ACTION === $attribute->name) {
                    $action = true;
                } elseif (self::LIVE_LISTENER === $attribute->name) {
                    $listeners[] = $attribute;
                }
            }
            if (!$action && [] === $listeners) {
                continue;
            }
            $actions[$method->name] = new TwigComponentAction($method->name, $this->converter->toRange($text, $method->nameStartOffset, $method->nameEndOffset - $method->nameStartOffset));
            foreach ($listeners as $listener) {
                $event = ($listener->argument('event') ?? $listener->positionalArgument(0))?->stringLiteral;
                if (null === $event || '' === $event->value) {
                    continue;
                }
                $events[] = new LiveComponentEvent(
                    $event->value,
                    $uri,
                    $this->converter->toRange($text, $event->startOffset, $event->endOffset - $event->startOffset),
                    true,
                    $component,
                    $method->name,
                );
            }
        }

        return [array_values($actions), $events];
    }

    /**
     * @param list<PhpMethodCall> $calls
     *
     * @return list<LiveComponentEvent>
     */
    private function emittedEvents(array $calls, string $component, string $uri, string $text): array
    {
        $events = [];
        foreach ($calls as $call) {
            if ('emit' !== $call->method || PhpMethodReceiverKind::This !== $call->receiverContext->kind) {
                continue;
            }
            $event = ($call->argument('event') ?? $call->positionalArgument(0))?->stringLiteral;
            if (null === $event) {
                continue;
            }
            $events[] = new LiveComponentEvent(
                $event->value,
                $uri,
                $this->converter->toRange($text, $event->startOffset, $event->endOffset - $event->startOffset),
                false,
                $component,
            );
        }

        return $events;
    }

    private function target(PhpAttribute $attribute, PhpAttributeTargetKind $kind): ?PhpAttributeTarget
    {
        foreach ($attribute->targets as $target) {
            if ($kind === $target->kind) {
                return $target;
            }
        }

        return null;
    }

    private function targetKey(PhpAttributeTargetKind $kind, string $className, ?string $memberName): string
    {
        return $kind->name."\0".$className."\0".$memberName;
    }
}
