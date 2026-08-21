<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $context = $this->extractor->completionContext($request->document->languageId(), $request->document->text(), $offset);
        if (null === $context) {
            return null;
        }
        $values = null === $context->kind()
            ? $this->controllerNames($request->project)
            : $this->members($request->project, $context->controller() ?? '', $context->kind());
        $items = [];
        foreach ($values as $value) {
            if (!str_starts_with($value, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $value,
                'kind' => null === $context->kind() ? 7 : 2,
                'detail' => null === $context->kind() ? 'Stimulus controller' : \sprintf('Stimulus %s', $context->kind()->value),
                'textEdit' => $this->protocol->textEdit($context->range(), $value),
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $controller = $this->indexes->forProject($project)->controller($reference->controller());
        $declarations = $this->sourceIndexes->forProject($project)->declarations($reference->controller());
        if (null === $controller && [] === $declarations) {
            return null;
        }
        if (null !== $reference->kind() && null !== $reference->member()) {
            if (!\in_array($reference->member(), $this->members($project, $reference->controller(), $reference->kind()), true)) {
                return null;
            }

            return $this->protocol->markdownHover(\sprintf(
                'Stimulus %s: `%s#%s`',
                $reference->kind()->value,
                $reference->controller(),
                $reference->member(),
            ));
        }
        $details = [\sprintf('Stimulus controller: `%s`', $reference->controller())];
        $source = $controller?->sourcePath() ?? (isset($declarations[0]) ? $this->uriConverter->convert($declarations[0]->uri()) : null);
        if (null !== $source) {
            $details[] = \sprintf('Source: `%s`', $source);
        }
        $details[] = 'Lazy: '.($controller?->isLazy() || ($declarations[0] ?? null)?->isLazy() ? 'yes' : 'no');
        if (null !== $controller) {
            $details[] = 'Vendor: '.($controller->isVendor() ? 'yes' : 'no');
        }
        foreach (StimulusMemberKind::cases() as $kind) {
            $members = $this->members($project, $reference->controller(), $kind);
            if ([] !== $members) {
                $details[] = ucfirst($kind->value).'s: `'.implode('`, `', $members).'`';
            }
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $locations = $this->declarationLocations($project, $reference);
        if ([] !== $locations) {
            return $locations;
        }
        if (null !== $reference->kind() && (null === $reference->member() || !\in_array($reference->member(), $this->members($project, $reference->controller(), $reference->kind()), true))) {
            return [];
        }
        $controller = $this->indexes->forProject($project)->controller($reference->controller());

        return null === $controller ? [] : [['uri' => $this->uriConverter->toUri($controller->sourcePath()), 'range' => $this->protocol->zeroRange()]];
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        $locations = $this->declarationLocations($project, $reference);
        foreach ($this->sourceIndexes->forProject($project)->references($reference->controller(), $reference->kind(), $reference->member()) as $candidate) {
            $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
        }

        return $locations;
    }

    public function links(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $links = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text())->references() as $reference) {
            $locations = $this->declarationLocations($request->project, $reference);
            $target = $locations[0]['uri'] ?? null;
            if (!\is_string($target)) {
                $controller = $this->indexes->forProject($request->project)->controller($reference->controller());
                $target = null === $controller ? null : $this->uriConverter->toUri($controller->sourcePath());
            }
            if (null !== $target) {
                $links[] = ['range' => $this->protocol->range($reference->range()), 'target' => $target];
            }
        }

        return $links;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        if (!$this->indexes->forProject($request->project)->isComplete()) {
            return [];
        }
        $known = array_fill_keys($this->controllerNames($request->project), true);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text())->references() as $reference) {
            if (null !== $reference->kind() || isset($known[$reference->controller()])) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'stimulus.unknown_controller', \sprintf('Unknown Stimulus controller "%s".', $reference->controller()));
        }

        return $diagnostics;
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId(), ['javascript', 'typescript'], true)) {
            return null;
        }
        $lenses = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), $request->document->languageId(), $request->document->text())->declarations() as $declaration) {
            $references = $this->sourceIndexes->forProject($request->project)->references($declaration->name());
            $locations = array_map(fn (StimulusReference $reference): array => $this->protocol->location($reference->uri(), $reference->range()), $references);
            $count = \count($locations);
            $lenses[] = $this->protocol->referenceLens($declaration->range(), \sprintf('%d Stimulus controller usage%s', $count, 1 === $count ? '' : 's'), $declaration->uri(), $locations);
        }

        return $lenses;
    }

    /** @return list<string> */
    private function controllerNames(Project $project): array
    {
        $names = array_map(static fn (StimulusController $controller): string => $controller->name(), $this->indexes->forProject($project)->controllers());
        foreach ($this->sourceIndexes->forProject($project)->declarations() as $declaration) {
            $names[] = $declaration->name();
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function members(Project $project, string $controllerName, StimulusMemberKind $kind): array
    {
        $controller = $this->indexes->forProject($project)->controller($controllerName);
        $members = null === $controller ? [] : match ($kind) {
            StimulusMemberKind::Action => $controller->actions(),
            StimulusMemberKind::ClassName => $controller->classes(),
            StimulusMemberKind::Outlet => $controller->outlets(),
            StimulusMemberKind::Target => $controller->targets(),
            StimulusMemberKind::Value => $controller->values(),
        };
        foreach ($this->sourceIndexes->forProject($project)->declarations($controllerName) as $declaration) {
            foreach ($declaration->members() as $member) {
                if ($kind === $member->kind()) {
                    $members[] = $member->name();
                }
            }
        }
        $members = array_values(array_unique($members));
        sort($members);

        return $members;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{StimulusReference, Project}|null
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
            if ($this->contains($request->document, $reference->range(), $offset)) {
                return [$reference, $request->project];
            }
        }
        foreach ($facts->declarations() as $declaration) {
            foreach ($declaration->members() as $member) {
                if ($this->contains($request->document, $member->range(), $offset)) {
                    return [new StimulusReference($declaration->name(), $member->kind(), $member->name(), $declaration->uri(), $member->range()), $request->project];
                }
            }
            if ($this->contains($request->document, $declaration->range(), $offset)) {
                return [new StimulusReference($declaration->name(), null, null, $declaration->uri(), $declaration->range()), $request->project];
            }
        }

        return null;
    }

    /** @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}> */
    private function declarationLocations(Project $project, StimulusReference $reference): array
    {
        $locations = [];
        foreach ($this->sourceIndexes->forProject($project)->declarations($reference->controller()) as $declaration) {
            if (null === $reference->kind()) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
                continue;
            }
            foreach ($declaration->members() as $member) {
                if ($reference->kind() === $member->kind() && $reference->member() === $member->name()) {
                    $locations[] = $this->protocol->location($declaration->uri(), $member->range());
                }
            }
        }

        return $locations;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }
}
