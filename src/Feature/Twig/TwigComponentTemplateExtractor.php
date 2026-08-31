<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigQuotedArgumentMatcher;
use Symfony\Lsp\Project\Project;

final class TwigComponentTemplateExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigComponentNameResolver $names,
        private readonly TwigCommentParser $comments,
        private readonly TwigQuotedArgumentMatcher $matcher,
    ) {
    }

    public function extract(Project $project, string $uri, string $text): TwigComponentSourceFacts
    {
        $text = $this->comments->mask($text);
        $name = $this->names->anonymous($project, $uri);
        $components = [];
        if (null !== $name) {
            $components[] = new TwigComponent($name, $uri, $this->converter->toRange($text, 0, 0), template: $this->names->template($project, $uri));
        }

        $references = [];
        preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b/', $text, $tags, \PREG_OFFSET_CAPTURE);
        foreach ($tags[1] as [$componentName, $offset]) {
            if ('block' !== strtolower($componentName)) {
                $references[] = new TwigComponentReference($componentName, $uri, $this->converter->toRange($text, $offset, \strlen($componentName)));
            }
        }
        foreach ($this->matcher->functionCalls($text, ['component']) as $function) {
            $references[] = new TwigComponentReference($function->value, $uri, $function->range);
        }

        $actionReferences = [];
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

        return new TwigComponentSourceFacts($uri, $components, $references, $actionReferences);
    }

    private function liveActionName(string $value): string
    {
        $parts = explode('|', $value);

        return explode(':', end($parts))[0];
    }
}
