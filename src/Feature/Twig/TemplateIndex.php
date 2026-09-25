<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TemplateSourceFacts> */
final class TemplateIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, TemplateDeclaration> */
    private array $runtime = [];
    private bool $complete = false;
    /** @var list<string> */
    private array $globals = [];

    /** @var array<string, TemplateDeclaration> */
    private array $declarations = [];

    /** @var array<string, list<TemplateReference>> */
    private array $references = [];

    public function __construct(private readonly DependencyInjectionSourceIndex $classes)
    {
        parent::__construct();
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
        $this->invalidate();
    }

    public function get(string $name): ?TemplateDeclaration
    {
        $this->derive();

        return $this->declarations[$this->normalize($name)] ?? null;
    }

    /** @return list<TemplateDeclaration> */
    public function matching(string $prefix): array
    {
        $this->derive();
        $prefix = $this->normalize($prefix);

        return array_values(array_filter(
            $this->declarations,
            static fn (TemplateDeclaration $template): bool => str_starts_with($template->name, $prefix),
        ));
    }

    /** @return list<TemplateReference> */
    public function references(string $name): array
    {
        $this->derive();

        return $this->supported($this->references[$this->normalize($name)] ?? []);
    }

    /** @return list<TemplateReference> */
    public function referencesForUri(string $uri): array
    {
        $facts = $this->factsForUri($uri);

        return $this->supported(null === $facts ? [] : $facts->references);
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

    protected function build(): void
    {
        $declarations = [];
        $this->references = [];
        foreach ($this->facts() as $facts) {
            if (null !== $declaration = $facts->declaration) {
                $declarations[$declaration->name] ??= $declaration;
            }
            foreach ($facts->references as $reference) {
                $this->references[$this->normalize($reference->name)][] = $reference;
            }
        }

        $this->declarations = array_replace($this->runtime, $declarations);
        ksort($this->declarations);
    }

    /**
     * @param list<TemplateReference> $references
     *
     * @return list<TemplateReference>
     */
    private function supported(array $references): array
    {
        return array_values(array_filter(
            $references,
            fn (TemplateReference $reference): bool => TemplatePhpReferenceResolver::supports($reference, $this->classes),
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
