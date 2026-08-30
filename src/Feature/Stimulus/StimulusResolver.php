<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class StimulusResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly StimulusIndexRegistry $indexes,
        private readonly StimulusSourceIndexRegistry $sourceIndexes,
        private readonly StimulusExtractor $extractor,
    ) {
    }

    /** @return list<string> */
    public function controllerNames(Project $project): array
    {
        $names = [];
        foreach ($this->indexes->forProject($project)->controllers() as $controller) {
            $names[$controller->name()] = true;
        }
        foreach ($this->sourceIndexes->forProject($project)->declarations() as $declaration) {
            $names[$declaration->name()] = true;
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    public function members(Project $project, string $controllerName, StimulusMemberKind $kind): array
    {
        $controller = $this->indexes->forProject($project)->controller($controllerName);
        $members = null === $controller ? [] : match ($kind) {
            StimulusMemberKind::Action => $controller->actions(),
            StimulusMemberKind::ClassName => $controller->classes(),
            StimulusMemberKind::Outlet => $controller->outlets(),
            StimulusMemberKind::Target => $controller->targets(),
            StimulusMemberKind::Value => $controller->values(),
        };
        $unique = [];
        foreach ($members as $member) {
            $unique[$member] = true;
        }
        foreach ($this->sourceIndexes->forProject($project)->declarations($controllerName) as $declaration) {
            foreach ($declaration->members() as $member) {
                if ($kind === $member->kind()) {
                    $unique[$member->name()] = true;
                }
            }
        }
        $members = array_keys($unique);
        sort($members);

        return $members;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{StimulusReference, Project}|null
     */
    public function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $facts = $this->extractor->extract($request->project, $request->document->uri, $request->document->languageId, $request->document->text);
        foreach ($facts->references() as $reference) {
            if ($this->converter->containsByteOffset($request->document->text, $reference->range(), $offset, inclusiveEnd: true)) {
                return [$reference, $request->project];
            }
        }
        foreach ($facts->declarations() as $declaration) {
            foreach ($declaration->members() as $member) {
                if ($this->converter->containsByteOffset($request->document->text, $member->range(), $offset, inclusiveEnd: true)) {
                    return [new StimulusReference($declaration->name(), $member->kind(), $member->name(), $declaration->uri(), $member->range()), $request->project];
                }
            }
            if ($this->converter->containsByteOffset($request->document->text, $declaration->range(), $offset, inclusiveEnd: true)) {
                return [new StimulusReference($declaration->name(), null, null, $declaration->uri(), $declaration->range()), $request->project];
            }
        }

        return null;
    }

    /** @return list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}> */
    public function declarationLocations(Project $project, StimulusReference $reference): array
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
}
