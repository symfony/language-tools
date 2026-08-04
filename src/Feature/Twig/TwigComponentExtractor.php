<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;

final class TwigComponentExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(Project $project, string $uri, string $languageId, string $text): TwigComponentSourceFacts
    {
        $components = [];
        $references = [];
        $actionReferences = [];
        $events = [];
        if ('php' === $languageId) {
            preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $namespace);
            $namespace = isset($namespace[1]) ? trim($namespace[1]).'\\' : '';
            preg_match_all(
                '/#\[\s*[^\r\n]*?\b(?:AsTwigComponent|AsLiveComponent)\b\s*(?:\((.*?)\))?\s*]\s*(?:(?:final|readonly|abstract)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/s',
                $text,
                $matches,
                \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
            );
            foreach ($matches as $match) {
                $arguments = \is_string($match[1][0] ?? null) ? $match[1][0] : '';
                $class = $match[2][0] ?? null;
                $offset = $match[2][1];
                if (!\is_string($class)) {
                    continue;
                }
                $template = $this->attributeString($arguments, 'template');
                $name = $this->attributeString($arguments, 'name')
                    ?? $this->firstString($arguments)
                    ?? $this->nameFromTemplate($template)
                    ?? $this->nameFromClass($namespace.$class)
                    ?? $class;
                preg_match_all('/\bpublic\s+(?:(?:readonly|static)\s+)*(?:[^$(),;=]+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $properties);
                preg_match_all('/#\[\s*(?:[^\]\r\n]*\\\\)?LiveProp\b[^]]*]\s*(?:(?:public|protected|private|readonly|static)\s+)*(?:[^$(),;=]+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $liveProperties);
                $componentProperties = array_values(array_unique([...$properties[1], ...$liveProperties[1]]));
                sort($componentProperties);
                preg_match_all('/#\[\s*(?:[^\]\r\n]*\\\\)?(?:LiveAction|LiveListener)\b[^]]*](?:\s*#\[[^]]+])*\s*(?:(?:public|protected|private|final|static)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $actionMatches, \PREG_OFFSET_CAPTURE);
                $actions = [];
                foreach ($actionMatches[1] as [$action, $actionOffset]) {
                    $actions[$action] = new TwigComponentAction($action, $this->range($text, $actionOffset, \strlen($action)));
                }
                preg_match_all('/#\[\s*(?:[^\]\r\n]*\\\\)?LiveListener\s*\(\s*(?:event\s*:\s*)?([\'"])([^\'"]+)\1[^]]*](?:\s*#\[[^]]+])*\s*(?:(?:public|protected|private|final|static)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $listenerMatches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($listenerMatches as $listener) {
                    $events[] = new LiveComponentEvent(
                        $listener[2][0],
                        $uri,
                        $this->range($text, $listener[2][1], \strlen($listener[2][0])),
                        true,
                        $name,
                        $listener[3][0],
                    );
                }
                $live = str_contains((string) $match[0][0], 'AsLiveComponent');
                $components[] = new TwigComponent(
                    $name,
                    $uri,
                    $this->range($text, $offset, \strlen($class)),
                    $namespace.$class,
                    $template,
                    $componentProperties,
                    $live,
                    array_values($actions),
                );
                if ($live) {
                    preg_match_all('/(?:->|\b)emit\s*\(\s*([\'"])([^\'"]+)\1/', $text, $emitMatches, \PREG_OFFSET_CAPTURE);
                    foreach ($emitMatches[2] as [$event, $eventOffset]) {
                        $events[] = new LiveComponentEvent($event, $uri, $this->range($text, $eventOffset, \strlen($event)), false, $name);
                    }
                }
            }
        } elseif ('twig' === $languageId) {
            $name = $this->anonymousName($project, $uri);
            if (null !== $name) {
                $components[] = new TwigComponent($name, $uri, $this->range($text, 0, 0), template: $this->templateName($project, $uri));
            }
            preg_match_all('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b/', $text, $tags, \PREG_OFFSET_CAPTURE);
            foreach ($tags[1] as [$componentName, $offset]) {
                if ('block' !== strtolower($componentName)) {
                    $references[] = new TwigComponentReference($componentName, $uri, $this->range($text, $offset, \strlen($componentName)));
                }
            }
            preg_match_all('/\bcomponent\s*\(\s*([\'"])([^\'"]+)\1/', $text, $functions, \PREG_OFFSET_CAPTURE);
            foreach ($functions[2] as [$componentName, $offset]) {
                $references[] = new TwigComponentReference($componentName, $uri, $this->range($text, $offset, \strlen($componentName)));
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
                        $actionReferences[] = new TwigComponentActionReference($componentTag[1][0], $action, $uri, $this->range($text, $offset, \strlen($action)));
                    }
                }
            } else {
                preg_match_all('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]+)\1/', $text, $actionAttributes, \PREG_OFFSET_CAPTURE);
                foreach ($actionAttributes[2] as [$actionValue, $actionOffset]) {
                    $action = $this->liveActionName($actionValue);
                    $offset = $actionOffset + (int) strrpos($actionValue, $action);
                    $actionReferences[] = new TwigComponentActionReference($name, $action, $uri, $this->range($text, $offset, \strlen($action)));
                }
                preg_match_all('/\blive_action\s*\(\s*([\'"])([^\'"]+)\1/', $text, $liveActions, \PREG_OFFSET_CAPTURE);
                foreach ($liveActions[2] as [$actionValue, $actionOffset]) {
                    $action = $this->liveActionName($actionValue);
                    $offset = $actionOffset + (int) strrpos($actionValue, $action);
                    $actionReferences[] = new TwigComponentActionReference($name, $action, $uri, $this->range($text, $offset, \strlen($action)));
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

    private function attributeString(string $arguments, string $name): ?string
    {
        return preg_match('/\b'.preg_quote($name, '/').'\s*:\s*([\'"])([^\'"]+)\1/', $arguments, $match) ? $match[2] : null;
    }

    private function firstString(string $arguments): ?string
    {
        return preg_match('/^\s*([\'"])([^\'"]+)\1/', $arguments, $match) ? $match[2] : null;
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
        $path = str_replace('\\', '/', rawurldecode((string) parse_url($uri, \PHP_URL_PATH)));
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/templates/';

        return str_starts_with($path, $root) ? substr($path, \strlen($root)) : null;
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
