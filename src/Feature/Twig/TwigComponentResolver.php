<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;

final class TwigComponentResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TemplateIndexRegistry $templates,
        private readonly TwigComponentExtractor $extractor,
    ) {
    }

    /** @return array{TwigComponent, string}|null */
    public function liveActionCompletionContext(Project $project, string $uri, string $before): ?array
    {
        $component = null;
        $value = null;
        if (preg_match('/<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b[^>]*\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $tagComponent = $this->indexes->forProject($project)->get($match[1]);
            if ($tagComponent?->live) {
                $component = $tagComponent;
                $value = $match[3];
            }
        }
        if (null === $component && preg_match('/\bdata-live-action-param\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $component = $this->componentForUri($project, $uri);
            $value = $match[2];
        }
        if (null === $component && preg_match('/\blive_action\s*\(\s*(?:actionName\s*[:=](?![=>])\s*)?([\'"])([^\'"]*)$/s', $before, $match)) {
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

    /** @return list<string> */
    public function anonymousComponentNames(Project $project): array
    {
        $index = $this->indexes->forProject($project);
        $templates = $this->templates->forProject($project);
        $names = [];
        $directory = rtrim($index->anonymousTemplateDirectory(), '/').'/';
        foreach ($templates->matching('') as $template) {
            $templateName = $template->name;
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
                    $names[$name] = true;
                }
                break;
            }
        }

        return array_keys($names);
    }

    public function anonymousTemplateExists(Project $project, string $name): bool
    {
        $index = $this->indexes->forProject($project);
        $templates = $this->templates->forProject($project);
        $path = str_replace(':', '/', $name);
        $directory = rtrim($index->anonymousTemplateDirectory(), '/');
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

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigComponent, TwigComponentAction, Project}|null
     */
    public function resolveAction(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $facts = $this->extractor->extract($request->project, $request->document->uri, $request->document->languageId, $request->document->text);
        foreach ($facts->actionReferences as $reference) {
            if (!$this->converter->containsByteOffset($request->document->text, $reference->range, $offset, inclusiveEnd: true)) {
                continue;
            }
            $component = $this->indexes->forProject($request->project)->get($reference->component);
            if (null === $component) {
                return null;
            }
            foreach ($component->actions as $action) {
                if ($reference->action === $action->name) {
                    return [$component, $action, $request->project];
                }
            }
        }
        foreach ($facts->components as $component) {
            foreach ($component->actions as $action) {
                if ($this->converter->containsByteOffset($request->document->text, $action->range, $offset, inclusiveEnd: true)) {
                    return [$this->indexes->forProject($request->project)->get($component->name) ?? $component, $action, $request->project];
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
    public function resolveComponent(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $facts = $this->extractor->extract($request->project, $request->document->uri, $request->document->languageId, $request->document->text);
        foreach ($facts->references as $reference) {
            if ($this->converter->containsByteOffset($request->document->text, $reference->range, $offset, inclusiveEnd: true)) {
                $component = $this->indexes->forProject($request->project)->get($reference->name);

                return null === $component ? null : [$component, $request->project];
            }
        }
        foreach ($facts->components as $component) {
            if ($this->converter->containsByteOffset($request->document->text, $component->range, $offset, inclusiveEnd: true)) {
                return [$this->indexes->forProject($request->project)->get($component->name) ?? $component, $request->project];
            }
        }

        return null;
    }

    private function componentForUri(Project $project, string $uri): ?TwigComponent
    {
        foreach ($this->indexes->forProject($project)->components() as $component) {
            foreach ($this->indexes->forProject($project)->declarations($component->name) as $declaration) {
                if ($uri === $declaration->uri && $component->live) {
                    return $component;
                }
            }
        }

        return null;
    }
}
