<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;

final class TwigComponentTemplateExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigComponentNameResolver $names,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCallArgumentResolver $arguments,
    ) {
    }

    public function extract(Project $project, string $uri, string $text): TwigComponentSourceFacts
    {
        $document = $this->parser->parse($text);
        $masked = $document->markup();
        $name = $this->names->anonymous($project, $uri);
        $components = [];
        if (null !== $name) {
            $components[] = new TwigComponent($name, $uri, $this->converter->toRange($text, 0, 0), template: $this->names->template($project, $uri));
        }

        $references = [];
        preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b/', $masked, $tags, \PREG_OFFSET_CAPTURE);
        foreach ($tags[1] as [$componentName, $offset]) {
            if ('block' !== strtolower($componentName)) {
                $references[] = new TwigComponentReference($componentName, $uri, $this->converter->toRange($text, $offset, \strlen($componentName)));
            }
        }

        $actionReferences = [];
        if (null === $name) {
            preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b([^>]*)>/s', $masked, $componentTags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($componentTags as $componentTag) {
                $attributes = $componentTag[2][0];
                $attributesOffset = $componentTag[2][1];
                preg_match_all('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]+)\1/', $attributes, $actionAttributes, \PREG_OFFSET_CAPTURE);
                foreach ($actionAttributes[2] as [$actionValue, $actionOffset]) {
                    $this->appendLiveAction($actionReferences, $componentTag[1][0], $uri, $text, $actionValue, $attributesOffset + $actionOffset);
                }
            }
        } else {
            preg_match_all('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]+)\1/', $masked, $actionAttributes, \PREG_OFFSET_CAPTURE);
            foreach ($actionAttributes[2] as [$actionValue, $actionOffset]) {
                $this->appendLiveAction($actionReferences, $name, $uri, $text, $actionValue, $actionOffset);
            }
        }

        foreach ($document->nodesOfType('function_call') as $call) {
            $identifier = $document->directChild($call, 'function_identifier');
            if (null === $identifier) {
                continue;
            }
            $function = $document->text($identifier);
            if ('component' === $function) {
                $argument = $this->arguments->resolve($document, $call)->get(0, 'name');
                $literal = null === $argument ? null : $document->soleStringLiteral($argument);
                if (null !== $literal) {
                    $references[] = new TwigComponentReference(
                        $literal->value,
                        $uri,
                        $this->converter->toRange($text, $literal->startOffset, $literal->endOffset - $literal->startOffset),
                    );
                }
            } elseif ('live_action' === $function && null !== $name) {
                $argument = $this->arguments->resolve($document, $call)->get(0, 'actionName');
                $literal = null === $argument ? null : $document->soleStringLiteral($argument);
                if (null !== $literal) {
                    $action = $this->liveActionName($literal->value);
                    $offset = $literal->startOffset + (int) strrpos($literal->value, $action);
                    $actionReferences[] = new TwigComponentActionReference($name, $action, $uri, $this->converter->toRange($text, $offset, \strlen($action)));
                }
            }
        }

        return new TwigComponentSourceFacts($uri, $components, $references, $actionReferences);
    }

    /** @param list<TwigComponentActionReference> $references */
    private function appendLiveAction(array &$references, string $component, string $uri, string $text, string $value, int $valueOffset): void
    {
        $raw = substr($text, $valueOffset, \strlen($value));
        if (str_contains($raw, '{{') || str_contains($raw, '{%')) {
            return;
        }
        $action = $this->liveActionName($value);
        $offset = $valueOffset + (int) strrpos($value, $action);
        $references[] = new TwigComponentActionReference($component, $action, $uri, $this->converter->toRange($text, $offset, \strlen($action)));
    }

    private function liveActionName(string $value): string
    {
        $parts = explode('|', $value);

        return explode(':', end($parts))[0];
    }
}
