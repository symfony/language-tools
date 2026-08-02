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
        if ('php' === $languageId) {
            preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $namespace);
            $namespace = isset($namespace[1]) ? trim($namespace[1]).'\\' : '';
            preg_match_all(
                '/#\[\s*[^\r\n]*?\bAsTwigComponent\b\s*(?:\((.*?)\))?\s*]\s*(?:(?:final|readonly|abstract)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/s',
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
                $componentProperties = array_values(array_unique($properties[1]));
                sort($componentProperties);
                $components[] = new TwigComponent(
                    $name,
                    $uri,
                    $this->range($text, $offset, \strlen($class)),
                    $namespace.$class,
                    $template,
                    $componentProperties,
                );
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
        }

        return new TwigComponentSourceFacts($uri, $components, $references);
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
