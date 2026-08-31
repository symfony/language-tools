<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;

final class TwigComponentNameResolver
{
    public function __construct(private readonly TemplateNameResolver $templates)
    {
    }

    public function component(?string $name, ?string $template, string $className): string
    {
        if (null !== $name) {
            return $name;
        }

        return $this->fromTemplate($template) ?? $this->fromClass($className) ?? $this->shortClassName($className);
    }

    public function anonymous(Project $project, string $uri): ?string
    {
        return $this->fromTemplate($this->template($project, $uri));
    }

    public function template(Project $project, string $uri): ?string
    {
        return $this->templates->relative($project, $uri);
    }

    private function fromClass(string $className): ?string
    {
        $marker = '\\Twig\\Components\\';
        $offset = strpos($className, $marker);

        return false === $offset ? null : str_replace('\\', ':', substr($className, $offset + \strlen($marker)));
    }

    private function fromTemplate(?string $template): ?string
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

    private function shortClassName(string $className): string
    {
        $separator = strrpos($className, '\\');

        return false === $separator ? $className : substr($className, $separator + 1);
    }
}
