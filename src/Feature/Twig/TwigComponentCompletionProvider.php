<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentResolver $components,
        private readonly TwigCommentParser $commentParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($request->document->text(), $request->position);
        $before = substr($this->commentParser->mask($request->document->text()), 0, $cursor);
        $index = $this->indexes->forProject($request->project);
        $liveActionContext = $this->components->liveActionCompletionContext($request->project, $request->document->uri(), $before);
        if (null !== $liveActionContext) {
            [$component, $prefix] = $liveActionContext;
            $values = [];
            foreach ($component->actions() as $action) {
                $values[] = $action->name();
            }
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
            $names = [];
            foreach ($index->components() as $component) {
                $names[$component->name()] = true;
            }
            foreach ($index->runtimeNames() as $name) {
                $names[$name] = true;
            }
            foreach ($this->components->anonymousComponentNames($request->project) as $name) {
                $names[$name] = true;
            }
            $values = array_keys($names);
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
}
