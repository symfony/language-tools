<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TemplateIndexRegistry $templates,
        private readonly TwigComponentExtractor $extractor,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($request->document->text(), $request->position);
        $before = substr($this->commentParser->mask($request->document->text()), 0, $cursor);
        $index = $this->indexes->forProject($request->project);
        $liveActionContext = $this->liveActionCompletionContext($request->project, $request->document->uri(), $before);
        if (null !== $liveActionContext) {
            [$component, $prefix] = $liveActionContext;
            $values = array_map(static fn (TwigComponentAction $action): string => $action->name(), $component->actions());
            $detail = \sprintf('Live action of component %s', $component->name());
        } elseif (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\s+[^>]*?([A-Za-z_][A-Za-z0-9_]*)$/', $before, $match)) {
            $component = $index->get($match[1]);
            if (null === $component) {
                return null;
            }
            $prefix = $match[2];
            $values = $component->properties();
            $detail = \sprintf('Property of Twig component %s', $component->name());
        } elseif (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)?$/', $before, $match)) {
            $prefix = $match[1] ?? '';
            $values = array_values(array_unique([
                ...array_map(static fn (TwigComponent $component): string => $component->name(), $index->components()),
                ...$index->runtimeNames(),
                ...$this->anonymousComponentNames($index->anonymousTemplateDirectory(), $this->templates->forProject($request->project)),
            ]));
            sort($values);
            $detail = 'Symfony Twig component';
        } else {
            return null;
        }
        $start = $this->converter->toPosition($request->document->text(), $cursor - \strlen($prefix));
        $items = [];
        foreach ($values as $value) {
            if (!str_starts_with($value, $prefix)) {
                continue;
            }
            $items[] = [
                'label' => $value,
                'kind' => 6,
                'detail' => $detail,
                'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $value),
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction] = $action;

            return $this->protocol->markdownHover(\sprintf('Live action: `%s#%s`', $component->name(), $componentAction->name()));
        }
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component] = $resolved;
        $details = [\sprintf('%s component: `%s`', $component->isLive() ? 'Live' : 'Twig', $component->name())];
        if (null !== $component->className()) {
            $details[] = \sprintf('Class: `%s`', $component->className());
        }
        if (null !== $component->template()) {
            $details[] = \sprintf('Template: `%s`', $component->template());
        }
        if ([] !== $component->properties()) {
            $details[] = \sprintf('Properties: `%s`', implode('`, `', $component->properties()));
        }
        if ([] !== $component->actions()) {
            $details[] = \sprintf('Actions: `%s`', implode('`, `', array_map(static fn (TwigComponentAction $action): string => $action->name(), $component->actions())));
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    public function definition(array $params): ?array
    {
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = [];
            foreach ($this->indexes->forProject($project)->declarations($component->name()) as $declaration) {
                foreach ($declaration->actions() as $declarationAction) {
                    if ($componentAction->name() === $declarationAction->name()) {
                        $locations[] = $this->protocol->location($declaration->uri(), $declarationAction->range());
                    }
                }
            }

            return $locations;
        }
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component, $project] = $resolved;

        return array_map(fn (TwigComponent $declaration): array => $this->protocol->location($declaration->uri(), $declaration->range()), $this->indexes->forProject($project)->declarations($component->name()));
    }

    public function references(array $params): ?array
    {
        $action = $this->resolveAction($params);
        if (null !== $action) {
            [$component, $componentAction, $project] = $action;
            $locations = $this->definition($params) ?? [];
            foreach ($this->indexes->forProject($project)->actionReferences($component->name(), $componentAction->name()) as $reference) {
                $locations[] = $this->protocol->location($reference->uri(), $reference->range());
            }

            return $locations;
        }
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$component, $project] = $resolved;

        return array_map(fn (TwigComponentReference $reference): array => $this->protocol->location($reference->uri(), $reference->range()), $this->indexes->forProject($project)->references($component->name()));
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete() || !$index->isRuntimeComplete()) {
            return null;
        }
        $templates = $this->templates->forProject($request->project);
        if (!$templates->isComplete()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), 'twig', $request->document->text())->references() as $reference) {
            $name = $reference->name();
            if (null !== $index->get($name)
                || $index->hasRuntimeName($name)
                || $this->anonymousTemplateExists($index->anonymousTemplateDirectory(), $templates, $name)
            ) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'twig_component.not_found', \sprintf('Twig component "%s" does not exist.', $name));
        }

        return $diagnostics;
    }

    /**
     * Reverses the ComponentTemplateFinder anonymous template rules into component names.
     *
     * @return list<string>
     */
    private function anonymousComponentNames(string $directory, TemplateIndex $templates): array
    {
        $names = [];
        $directory = rtrim($directory, '/').'/';
        foreach ($templates->matching('') as $template) {
            $templateName = $template->name();
            if (str_starts_with($templateName, $directory)) {
                $path = substr($templateName, \strlen($directory));
            } elseif (str_starts_with($templateName, '@') && \is_int($marker = strpos($templateName, '/components/'))) {
                $path = substr($templateName, 1, $marker - 1).'/'.substr($templateName, $marker + \strlen('/components/'));
            } else {
                continue;
            }
            foreach (['/index.html.twig', '.html.twig'] as $suffix) {
                if (!str_ends_with($path, $suffix)) {
                    continue;
                }
                $name = str_replace('/', ':', substr($path, 0, -\strlen($suffix)));
                if ('' !== $name) {
                    $names[] = $name;
                }
                break;
            }
        }

        return array_values(array_unique($names));
    }

    // Mirrors the template candidates of the ComponentTemplateFinder anonymous resolution.
    private function anonymousTemplateExists(string $directory, TemplateIndex $templates, string $name): bool
    {
        $path = str_replace(':', '/', $name);
        $directory = rtrim($directory, '/');
        $candidates = [
            $directory.'/'.$path.'.html.twig',
            $directory.'/'.$path.'/index.html.twig',
        ];
        $parts = explode('/', $path, 2);
        if (2 === \count($parts)) {
            $candidates[] = '@'.$parts[0].'/components/'.$parts[1].'.html.twig';
            $candidates[] = '@'.$parts[0].'/components/'.$parts[1].'/index.html.twig';
        }
        foreach ($candidates as $candidate) {
            if (null !== $templates->get($candidate)) {
                return true;
            }
        }

        return false;
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $lenses = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), 'php', $request->document->text())->components() as $component) {
            $references = $this->indexes->forProject($request->project)->references($component->name());
            $locations = array_map(fn (TwigComponentReference $reference): array => $this->protocol->location($reference->uri(), $reference->range()), $references);
            $count = \count($locations);
            $lenses[] = $this->protocol->referenceLens($component->range(), \sprintf('%d Twig component usage%s', $count, 1 === $count ? '' : 's'), $component->uri(), $locations);
        }

        return $lenses;
    }

    /** @return array{TwigComponent, string}|null */
    private function liveActionCompletionContext(Project $project, string $uri, string $before): ?array
    {
        $component = null;
        $value = null;
        if (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b[^>]*\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $tagComponent = $this->indexes->forProject($project)->get($match[1]);
            if ($tagComponent?->isLive()) {
                $component = $tagComponent;
                $value = $match[3];
            }
        }
        if (null === $component && preg_match('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $component = $this->componentForUri($project, $uri);
            $value = $match[2];
        }
        if (null === $component && preg_match('/\blive_action\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $component = $this->componentForUri($project, $uri);
            $value = $match[2];
        }
        if (null === $component || null === $value) {
            return null;
        }
        $parts = explode('|', $value);
        $prefix = explode(':', end($parts))[0];

        return [$component, $prefix];
    }

    private function componentForUri(Project $project, string $uri): ?TwigComponent
    {
        foreach ($this->indexes->forProject($project)->components() as $component) {
            foreach ($this->indexes->forProject($project)->declarations($component->name()) as $declaration) {
                if ($uri === $declaration->uri() && $component->isLive()) {
                    return $component;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, TwigComponentAction, Project}|null
     */
    private function resolveAction(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $facts = $this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text());
        foreach ($facts->actionReferences() as $reference) {
            if (!$this->contains($request->document->text(), $reference->range(), $offset)) {
                continue;
            }
            $component = $this->indexes->forProject($request->project)->get($reference->component());
            if (null === $component) {
                return null;
            }
            foreach ($component->actions() as $action) {
                if ($reference->action() === $action->name()) {
                    return [$component, $action, $request->project];
                }
            }
        }
        foreach ($facts->components() as $component) {
            foreach ($component->actions() as $action) {
                if ($this->contains($request->document->text(), $action->range(), $offset)) {
                    return [$this->indexes->forProject($request->project)->get($component->name()) ?? $component, $action, $request->project];
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $facts = $this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text());
        foreach ($facts->references() as $reference) {
            if ($this->contains($request->document->text(), $reference->range(), $offset)) {
                $component = $this->indexes->forProject($request->project)->get($reference->name());

                return null === $component ? null : [$component, $request->project];
            }
        }
        foreach ($facts->components() as $component) {
            if ($this->contains($request->document->text(), $component->range(), $offset)) {
                return [$this->indexes->forProject($request->project)->get($component->name()) ?? $component, $request->project];
            }
        }

        return null;
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }
}
