<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocument;
use Symfony\Lsp\Parser\Yaml\YamlScalar;

final class YamlDependencyInjectionDeclarationExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /** @return array{list<ServiceDeclaration>, list<ParameterDeclaration>} */
    public function extract(string $uri, string $text, YamlDocument $document, ?string $environment): array
    {
        /** @var list<PendingServiceDeclaration> $services */
        $services = [];
        $parameters = [];
        /** @var array<string, array{PendingServiceDeclaration, bool}> $currentServices */
        $currentServices = [];

        foreach ($document->mappings as $mapping) {
            if (!$this->includes($mapping->scope, $environment) || $mapping->isSequenceItem()) {
                continue;
            }
            if (2 === \count($mapping->path) && 'parameters' === $mapping->path[0]) {
                $parameters[] = new ParameterDeclaration(
                    $mapping->path[1],
                    $uri,
                    $this->range($text, $mapping->keyStartByte, $mapping->keyEndByte),
                    $this->environment($mapping->scope),
                );
                continue;
            }
            if (2 === \count($mapping->path) && 'services' === $mapping->path[0]) {
                $id = $mapping->path[1];
                if (str_starts_with($id, '_')) {
                    continue;
                }
                $service = new PendingServiceDeclaration(
                    $id,
                    $this->range($text, $mapping->keyStartByte, $mapping->keyEndByte),
                    $this->environment($mapping->scope),
                );
                $services[] = $service;
                $currentServices[$this->key($mapping->scope, $id)] = [$service, '' === $mapping->value];
                $target = $this->scalar($mapping->value);
                if (str_starts_with($target, '@')) {
                    $service->alias = ltrim(substr($target, 1), '?');
                }
                continue;
            }
            if (3 !== \count($mapping->path) || 'services' !== $mapping->path[0]) {
                continue;
            }
            $current = $currentServices[$this->key($mapping->scope, $mapping->path[1])] ?? null;
            if (null === $current || !$current[1]) {
                continue;
            }
            $target = $this->scalar($mapping->value);
            if ('class' === $mapping->path[2]) {
                $current[0]->className = '' !== $target ? ltrim($target, '\\') : null;
            } elseif ('alias' === $mapping->path[2] && '' !== $target = ltrim($target, '@?')) {
                $current[0]->alias = $target;
            } elseif ('decorates' === $mapping->path[2] && '' !== $target = ltrim($target, '@?')) {
                $current[0]->decorates = $target;
            }
        }

        foreach ($document->scalars as $scalar) {
            if (!$this->includes($scalar->environment, $environment) || !$this->isTag($scalar)) {
                continue;
            }
            $scope = null === $scalar->environment ? 'base' : 'when@'.$scalar->environment;
            $current = $currentServices[$this->key($scope, $scalar->path[1])] ?? null;
            if (null !== $current && $current[1]) {
                array_push($current[0]->tags, ...$this->tags($scalar->raw));
            }
        }

        return [
            array_map(static fn (PendingServiceDeclaration $service): ServiceDeclaration => $service->declaration($uri), $services),
            $parameters,
        ];
    }

    private function includes(?string $scope, ?string $environment): bool
    {
        return null === $environment || null === $scope || 'base' === $scope || $scope === $environment || 'when@'.$environment === $scope;
    }

    private function isTag(YamlScalar $scalar): bool
    {
        return 'services' === ($scalar->path[0] ?? null)
            && !str_starts_with($scalar->path[1] ?? '_', '_')
            && 'tags' === ($scalar->path[2] ?? null)
            && (3 === \count($scalar->path) || (4 === \count($scalar->path) && 'name' === $scalar->path[3]));
    }

    /** @return list<string> */
    private function tags(string $value): array
    {
        preg_match_all('/(?:\bname\s*:\s*)?[\'\"]?([A-Za-z_][A-Za-z0-9_.-]*)[\'\"]?/', $value, $matches);

        return array_values(array_filter($matches[1], static fn (string $tag): bool => !\in_array($tag, ['name', 'priority'], true)));
    }

    private function scalar(string $value): string
    {
        $value = trim($value);

        return \strlen($value) >= 2 && (("'" === $value[0] && str_ends_with($value, "'")) || ('"' === $value[0] && str_ends_with($value, '"')))
            ? substr($value, 1, -1)
            : $value;
    }

    private function key(string $scope, string $id): string
    {
        return $scope."\0".$id;
    }

    private function environment(string $scope): ?string
    {
        return 'base' === $scope ? null : substr($scope, \strlen('when@'));
    }

    private function range(string $text, int $start, int $end): Range
    {
        return new Range($this->positionConverter->toPosition($text, $start), $this->positionConverter->toPosition($text, $end));
    }
}
