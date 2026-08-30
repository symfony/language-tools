<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTarget;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;

final class TwigComponentExtractor
{
    private const AS_LIVE_COMPONENT = 'Symfony\\UX\\LiveComponent\\Attribute\\AsLiveComponent';
    private const AS_TWIG_COMPONENT = 'Symfony\\UX\\TwigComponent\\Attribute\\AsTwigComponent';
    private const LIVE_ACTION = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveAction';
    private const LIVE_LISTENER = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveListener';
    private const LIVE_PROP = 'Symfony\\UX\\LiveComponent\\Attribute\\LiveProp';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TemplateNameResolver $templateNameResolver,
        private readonly TwigCommentParser $commentParser,
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    public function extract(Project $project, string $uri, string $languageId, string $text): TwigComponentSourceFacts
    {
        $components = [];
        $references = [];
        $actionReferences = [];
        $events = [];
        if ('php' === $languageId) {
            $php = $this->phpParser->parse($text);
            $attributes = $php->attributes;
            foreach ($attributes as $attribute) {
                if (!\in_array($attribute->name, [self::AS_TWIG_COMPONENT, self::AS_LIVE_COMPONENT], true)
                    || null === $target = $this->attributeTarget($attribute, PhpAttributeTargetKind::Type)
                ) {
                    continue;
                }
                $className = $target->className;
                if (!array_any($php->typeDeclarations, static fn ($type): bool => $type->isClass() && $className === $type->name)) {
                    continue;
                }
                $separator = strrpos($className, '\\');
                $class = false === $separator ? $className : substr($className, $separator + 1);
                $template = $attribute->argument('template')?->stringLiteral?->value;
                $name = ($attribute->argument('name') ?? $attribute->positionalArgument(0))?->stringLiteral?->value;
                $name ??= $this->nameFromTemplate($template)
                    ?? $this->nameFromClass($className)
                    ?? $class;
                $componentProperties = [];
                foreach ($php->propertyDeclarations as $property) {
                    if ($className !== $property->className
                        || (!$property->isPublic() && !$this->hasAttribute($attributes, self::LIVE_PROP, PhpAttributeTargetKind::Property, $className, $property->name))
                    ) {
                        continue;
                    }
                    $componentProperties[] = $property->name;
                }
                $componentProperties = array_values(array_unique($componentProperties));
                sort($componentProperties);
                $actions = [];
                foreach ($php->methodDeclarations as $method) {
                    if ($className !== $method->className) {
                        continue;
                    }
                    $action = $this->hasAttribute($attributes, self::LIVE_ACTION, PhpAttributeTargetKind::Method, $className, $method->name);
                    $listeners = $this->attributesForTarget($attributes, self::LIVE_LISTENER, PhpAttributeTargetKind::Method, $className, $method->name);
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
                            $name,
                            $method->name,
                        );
                    }
                }
                $live = self::AS_LIVE_COMPONENT === $attribute->name;
                $components[] = new TwigComponent(
                    $name,
                    $uri,
                    $this->converter->toRange($text, $target->nameStartOffset, $target->nameEndOffset - $target->nameStartOffset),
                    $className,
                    $template,
                    $componentProperties,
                    $live,
                    array_values($actions),
                );
                if ($live) {
                    foreach ($php->methodCalls as $call) {
                        if ('emit' !== $call->method
                            || PhpMethodReceiverKind::This !== $call->receiverContext->kind
                            || $className !== $call->className
                        ) {
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
                            $name,
                        );
                    }
                }
            }
        } elseif ('twig' === $languageId) {
            $text = $this->commentParser->mask($text);
            $name = $this->anonymousName($project, $uri);
            if (null !== $name) {
                $components[] = new TwigComponent($name, $uri, $this->converter->toRange($text, 0, 0), template: $this->templateName($project, $uri));
            }
            preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b/', $text, $tags, \PREG_OFFSET_CAPTURE);
            foreach ($tags[1] as [$componentName, $offset]) {
                if ('block' !== strtolower($componentName)) {
                    $references[] = new TwigComponentReference($componentName, $uri, $this->converter->toRange($text, $offset, \strlen($componentName)));
                }
            }
            foreach ($this->matcher->functionCalls($text, ['component']) as $function) {
                $references[] = new TwigComponentReference($function->value, $uri, $function->range);
            }
            if (null === $name) {
                preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b([^>]*)>/s', $text, $componentTags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($componentTags as $componentTag) {
                    $attributes = $componentTag[2][0];
                    $attributesOffset = $componentTag[2][1];
                    preg_match_all('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]+)\1/', $attributes, $actionAttributes, \PREG_OFFSET_CAPTURE);
                    foreach ($actionAttributes[2] as [$actionValue, $actionOffset]) {
                        $action = $this->liveActionName($actionValue);
                        $offset = $attributesOffset + $actionOffset + (int) strrpos($actionValue, $action);
                        $actionReferences[] = new TwigComponentActionReference($componentTag[1][0], $action, $uri, $this->converter->toRange($text, $offset, \strlen($action)));
                    }
                }
            } else {
                preg_match_all('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]+)\1/', $text, $actionAttributes, \PREG_OFFSET_CAPTURE);
                foreach ($actionAttributes[2] as [$actionValue, $actionOffset]) {
                    $action = $this->liveActionName($actionValue);
                    $offset = $actionOffset + (int) strrpos($actionValue, $action);
                    $actionReferences[] = new TwigComponentActionReference($name, $action, $uri, $this->converter->toRange($text, $offset, \strlen($action)));
                }
                foreach ($this->matcher->functionCalls($text, ['live_action']) as $liveAction) {
                    $action = $this->liveActionName($liveAction->value);
                    $offset = $liveAction->offset + (int) strrpos($liveAction->value, $action);
                    $actionReferences[] = new TwigComponentActionReference($name, $action, $uri, $this->converter->toRange($text, $offset, \strlen($action)));
                }
            }
        }

        return new TwigComponentSourceFacts($uri, $components, $references, $actionReferences, $events);
    }

    private function liveActionName(string $value): string
    {
        $parts = explode('|', $value);

        return explode(':', end($parts))[0];
    }

    /**
     * @param list<PhpAttribute> $attributes
     */
    private function hasAttribute(array $attributes, string $name, PhpAttributeTargetKind $kind, string $className, ?string $memberName): bool
    {
        return [] !== $this->attributesForTarget($attributes, $name, $kind, $className, $memberName);
    }

    /**
     * @param list<PhpAttribute> $attributes
     *
     * @return list<PhpAttribute>
     */
    private function attributesForTarget(array $attributes, string $name, PhpAttributeTargetKind $kind, string $className, ?string $memberName): array
    {
        $matches = [];
        foreach ($attributes as $attribute) {
            if ($name !== $attribute->name) {
                continue;
            }
            foreach ($attribute->targets as $target) {
                if ($kind === $target->kind && $className === $target->className && $memberName === $target->memberName) {
                    $matches[] = $attribute;
                    break;
                }
            }
        }

        return $matches;
    }

    private function attributeTarget(PhpAttribute $attribute, PhpAttributeTargetKind $kind): ?PhpAttributeTarget
    {
        foreach ($attribute->targets as $target) {
            if ($kind === $target->kind) {
                return $target;
            }
        }

        return null;
    }

    private function anonymousName(Project $project, string $uri): ?string
    {
        return $this->nameFromTemplate($this->templateName($project, $uri));
    }

    private function nameFromClass(string $class): ?string
    {
        $marker = '\\Twig\\Components\\';
        $offset = strpos($class, $marker);

        return false === $offset ? null : str_replace('\\', ':', substr($class, $offset + \strlen($marker)));
    }

    private function nameFromTemplate(?string $template): ?string
    {
        if (null === $template || !str_starts_with($template, 'components/')) {
            return null;
        }
        $name = substr($template, \strlen('components/'));
        foreach (['.html.twig', '.twig'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -\strlen($suffix));
                break;
            }
        }

        return str_replace('/', ':', $name);
    }

    private function templateName(Project $project, string $uri): ?string
    {
        return $this->templateNameResolver->relative($project, $uri);
    }
}
