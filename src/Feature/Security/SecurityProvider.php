<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class SecurityProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly SecurityExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $context = $this->extractor->completionContext($document->languageId(), $document->text(), $offset);
        if (null === $context) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $names = match ($context->kind()) {
            SecuritySymbolKind::Firewall => array_map(static fn (SecurityFirewall $firewall): string => $firewall->name(), $index->firewalls()),
            SecuritySymbolKind::Provider => array_map(static fn (SecurityUserProvider $provider): string => $provider->name(), $index->providers()),
            SecuritySymbolKind::Role => array_map(static fn (SecurityRole $role): string => $role->name(), $index->roles()),
        };
        $sourceIndex = $this->sourceIndexes->forProject($project);
        $sourceNames = SecuritySymbolKind::Role === $context->kind()
            ? $sourceIndex->names($context->kind())
            : $sourceIndex->declarationNames($context->kind());
        array_push($names, ...$sourceNames);
        $names = array_values(array_unique($names));
        sort($names);
        $items = [];
        foreach ($names as $name) {
            if (str_starts_with($name, $context->prefix())) {
                $items[] = [
                    'label' => $name,
                    'kind' => 12,
                    'detail' => 'Symfony security '.$context->kind()->value,
                    'textEdit' => ['range' => $this->range($context->range()), 'newText' => $name],
                ];
            }
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $lines = match ($symbol->kind()) {
            SecuritySymbolKind::Firewall => $this->firewallHover($index, $symbol->name()),
            SecuritySymbolKind::Provider => $this->providerHover($index, $symbol->name()),
            SecuritySymbolKind::Role => $this->roleHover($index, $symbol->name()),
        };

        return [] === $lines ? null : ['contents' => ['kind' => 'markdown', 'value' => implode("\n", $lines)]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $declarations = array_filter(
            $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()),
            static fn (SecuritySourceSymbol $candidate): bool => $candidate->isDeclaration(),
        );

        return array_map(fn (SecuritySourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], array_values($declarations));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (SecuritySourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()));
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        if (!$index->isComplete()) {
            return [];
        }
        $sourceIndex = $this->sourceIndexes->forProject($project);
        $diagnostics = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->symbols() as $symbol) {
            if ($symbol->isDeclaration() || SecuritySymbolKind::Role === $symbol->kind()) {
                continue;
            }
            $known = SecuritySymbolKind::Firewall === $symbol->kind()
                ? null !== $index->firewall($symbol->name())
                : null !== $index->provider($symbol->name());
            if (!$known && !\in_array($symbol->name(), $sourceIndex->declarationNames($symbol->kind()), true)) {
                $diagnostics[] = [
                    'range' => $this->range($symbol->range()),
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => SecuritySymbolKind::Firewall === $symbol->kind() ? 'security.unknown_firewall' : 'security.unknown_provider',
                    'message' => \sprintf('Unknown security %s "%s".', $symbol->kind()->value, $symbol->name()),
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{SecuritySourceSymbol, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->symbols() as $symbol) {
            $start = $this->converter->toByteOffset($document->text(), $symbol->range()->start());
            $end = $this->converter->toByteOffset($document->text(), $symbol->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return [$symbol, $project];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function firewallHover(SecurityIndex $index, string $name): array
    {
        $firewall = $index->firewall($name);
        if (null === $firewall) {
            return [];
        }

        return [
            'Security firewall: `'.$name.'`',
            '',
            'Enabled: '.($firewall->isEnabled() ? 'yes' : 'no'),
            '',
            'Provider: '.(null === $firewall->provider() ? 'none' : '`'.$firewall->provider().'`'),
            '',
            'Stateless: '.($firewall->isStateless() ? 'yes' : 'no'),
            '',
            'Lazy: '.($firewall->isLazy() ? 'yes' : 'no'),
            '',
            'Authenticators: '.([] === $firewall->authenticators() ? 'none' : '`'.implode('`, `', $firewall->authenticators()).'`'),
        ];
    }

    /** @return list<string> */
    private function providerHover(SecurityIndex $index, string $name): array
    {
        $provider = $index->provider($name);
        if (null === $provider) {
            return [];
        }
        $firewalls = array_values(array_filter($index->firewalls(), static fn (SecurityFirewall $firewall): bool => $firewall->provider() === $name));

        return [
            'Security user provider: `'.$name.'`',
            '',
            'Type: `'.$provider->type().'`',
            '',
            'Firewalls: '.([] === $firewalls ? 'none' : '`'.implode('`, `', array_map(static fn (SecurityFirewall $firewall): string => $firewall->name(), $firewalls)).'`'),
        ];
    }

    /** @return list<string> */
    private function roleHover(SecurityIndex $index, string $name): array
    {
        $role = $index->role($name);
        if (null === $role) {
            return [];
        }
        $parents = [];
        foreach ($index->roles() as $candidate) {
            if (\in_array($name, $candidate->inheritedRoles(), true)) {
                $parents[] = $candidate->name();
            }
        }
        $voters = array_map(static fn (SecurityVoter $voter): string => $voter->className(), $index->voters());

        return [
            'Security role: `'.$name.'`',
            '',
            'Inherits: '.([] === $role->inheritedRoles() ? 'none' : '`'.implode('`, `', $role->inheritedRoles()).'`'),
            '',
            'Inherited by: '.([] === $parents ? 'none' : '`'.implode('`, `', $parents).'`'),
            '',
            'Registered voters: '.([] === $voters ? 'none' : '`'.implode('`, `', $voters).'`'),
        ];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
