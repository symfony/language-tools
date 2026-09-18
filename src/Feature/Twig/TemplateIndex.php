<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;

final class TemplateIndex
{
    /** @var array<string, TemplateDeclaration> */
    private array $runtime = [];
    /** @var array<string, TemplateDeclaration> */
    private array $sources = [];
    /** @var array<string, list<TemplateReference>> */
    private array $references = [];
    /** @var array<string, array{declaration: ?TemplateDeclaration, references: list<TemplateReference>}> */
    private array $overlays = [];
    private bool $complete = false;
    /** @var list<string> */
    private array $globals = [];

    public function __construct(private readonly DependencyInjectionSourceIndex $classes)
    {
    }

    /** @param list<string> $globals */
    public function replaceGlobals(array $globals): void
    {
        $this->globals = array_values(array_unique($globals));
        sort($this->globals);
    }

    public function replaceRuntime(bool $complete, TemplateDeclaration ...$templates): void
    {
        $this->runtime = [];
        foreach ($templates as $template) {
            // Twig resolves a name against its loader paths in order, so the
            // first declaration of a name wins over the paths it overrides
            $this->runtime[$template->name] ??= $template;
        }
        $this->complete = $complete;
    }

    public function replaceSources(TemplateDeclaration ...$templates): void
    {
        $this->sources = [];
        foreach ($templates as $template) {
            $this->sources[$template->uri] = $template;
        }
    }

    public function replaceReferences(TemplateReference ...$references): void
    {
        $this->references = [];
        foreach ($references as $reference) {
            $this->references[$reference->uri][] = $reference;
        }
    }

    /** @param list<TemplateReference> $references */
    public function replaceSource(string $uri, ?TemplateDeclaration $declaration, array $references): void
    {
        if (null === $declaration) {
            unset($this->sources[$uri]);
        } else {
            $this->sources[$uri] = $declaration;
        }
        $this->references[$uri] = $references;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri], $this->references[$uri]);
    }

    /** @param list<TemplateReference> $references */
    public function overlay(string $uri, ?TemplateDeclaration $declaration, array $references): void
    {
        $this->overlays[$uri] = ['declaration' => $declaration, 'references' => $references];
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    public function get(string $name): ?TemplateDeclaration
    {
        $name = $this->normalize($name);
        foreach ($this->overlays as $overlay) {
            if ($overlay['declaration']?->name === $name) {
                return $overlay['declaration'];
            }
        }
        foreach ($this->sourceDeclarations() as $declaration) {
            if ($declaration->name === $name) {
                return $declaration;
            }
        }

        return $this->runtime[$name] ?? null;
    }

    /** @return list<TemplateDeclaration> */
    public function matching(string $prefix): array
    {
        $prefix = $this->normalize($prefix);
        $templates = $this->runtime;
        foreach ($this->sourceDeclarations() as $template) {
            $templates[$template->name] = $template;
        }
        foreach ($this->overlays as $overlay) {
            if (null !== $overlay['declaration']) {
                $templates[$overlay['declaration']->name] = $overlay['declaration'];
            }
        }
        ksort($templates);

        return array_values(array_filter(
            $templates,
            static fn (TemplateDeclaration $template): bool => str_starts_with($template->name, $prefix),
        ));
    }

    /** @return list<TemplateReference> */
    public function references(string $name): array
    {
        $name = $this->normalize($name);
        $references = [];
        foreach ($this->references as $uri => $indexed) {
            if (isset($this->overlays[$uri])) {
                continue;
            }
            foreach ($indexed as $reference) {
                if ($this->normalize($reference->name) === $name && TemplatePhpReferenceResolver::supports($reference, $this->classes)) {
                    $references[] = $reference;
                }
            }
        }
        foreach ($this->overlays as $overlay) {
            foreach ($overlay['references'] as $reference) {
                if ($this->normalize($reference->name) === $name && TemplatePhpReferenceResolver::supports($reference, $this->classes)) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<TemplateReference> */
    public function referencesForUri(string $uri): array
    {
        $references = \array_key_exists($uri, $this->overlays)
            ? $this->overlays[$uri]['references']
            : $this->references[$uri] ?? [];

        return array_values(array_filter(
            $references,
            fn (TemplateReference $reference): bool => TemplatePhpReferenceResolver::supports($reference, $this->classes),
        ));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function isRuntimeTemplateUri(string $uri): bool
    {
        foreach ($this->runtime as $template) {
            if ($template->uri === $uri) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function variables(string $template): array
    {
        $variables = array_fill_keys($this->globals, true);
        foreach ($this->references($template) as $reference) {
            foreach ($reference->variables as $variable) {
                $variables[$variable] = true;
            }
        }
        $variables = array_keys($variables);
        sort($variables);

        return $variables;
    }

    public function isGlobal(string $name): bool
    {
        return \in_array($name, $this->globals, true);
    }

    /** @return list<TemplateDeclaration> */
    private function sourceDeclarations(): array
    {
        return array_values(array_filter(
            $this->sources,
            fn (TemplateDeclaration $template): bool => !isset($this->overlays[$template->uri]),
        ));
    }

    private function normalize(string $name): string
    {
        $name = (string) preg_replace('#/{2,}#', '/', str_replace('\\', '/', $name));
        if (str_starts_with($name, '@')) {
            $separator = strpos($name, '/');
            if (false === $separator) {
                return $name;
            }
            $shortname = $this->normalizeSegments(substr($name, $separator + 1));

            return null === $shortname ? $name : substr($name, 0, $separator + 1).$shortname;
        }
        $normalized = $this->normalizeSegments($name);
        if (null === $normalized) {
            return $name;
        }

        // Twig reads the namespace from the first character, so a main namespace
        // name keeps one slash instead of being stripped into a namespaced one
        return str_starts_with($normalized, '@') ? '/'.$normalized : $normalized;
    }

    /** @return ?string null when the name escapes the loader root */
    private function normalizeSegments(string $name): ?string
    {
        $segments = [];
        foreach (explode('/', ltrim($name, '/')) as $segment) {
            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }
                array_pop($segments);
            } elseif ('.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
