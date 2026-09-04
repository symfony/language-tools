<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Yaml\YamlDocument;
use Symfony\Lsp\Parser\Yaml\YamlScalar;

final class YamlDependencyInjectionReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /** @return list<DependencyInjectionReference> */
    public function extract(string $uri, string $text, YamlDocument $document, ?string $environment): array
    {
        /** @var array<int, array<int, list<DependencyInjectionReference>>> $sectionLines */
        $sectionLines = [];
        /** @var array<int, list<DependencyInjectionReference>> $configurationLines */
        $configurationLines = [];
        $nestedServices = $this->nestedServices($document, $environment);

        foreach ($document->scalars as $scalar) {
            if (!$this->includes($scalar->environment, $environment)) {
                continue;
            }
            $inSection = \in_array($scalar->path[0] ?? null, ['parameters', 'services'], true);
            if (!$inSection && !str_contains('/'.$uri, '/config/')) {
                continue;
            }
            $content = substr($text, $scalar->contentStartByte, $scalar->contentEndByte - $scalar->contentStartByte);
            preg_match_all('/^.*(?:\R|$)/m', $content, $lines, \PREG_OFFSET_CAPTURE);
            foreach ($lines[0] as [$rawLine, $relativeOffset]) {
                $lineOffset = $scalar->contentStartByte + $relativeOffset;
                $lineNumber = $this->positionConverter->toPosition($text, $lineOffset)->line;
                $line = rtrim($rawLine, "\r\n");
                if ($inSection) {
                    $sectionLines[$lineNumber] ??= [[], [], []];
                    array_push($sectionLines[$lineNumber][0], ...$this->serviceReferences($uri, $text, $line, $lineOffset, $scalar->environment));
                    array_push($sectionLines[$lineNumber][1], ...$this->parameterReferences($uri, $text, $line, $lineOffset, $scalar->environment));
                } else {
                    $configurationLines[$lineNumber] ??= [];
                    array_push($configurationLines[$lineNumber], ...$this->parameterReferences($uri, $text, $line, $lineOffset, $scalar->environment));
                }
            }
            if ($inSection && $this->isUnprefixedAlias($scalar, $nestedServices)) {
                $target = $this->scalar($scalar->raw);
                $offset = strpos($scalar->raw, $target);
                if (false !== $offset) {
                    $start = $scalar->startByte + $offset;
                    $line = $this->positionConverter->toPosition($text, $start)->line;
                    $sectionLines[$line][2][] = new DependencyInjectionReference(
                        DependencyInjectionSymbolKind::Service,
                        ltrim($target, '@?'),
                        $uri,
                        $this->positionConverter->toRange($text, $start, \strlen(ltrim($target, '@?'))),
                        environment: $scalar->environment,
                    );
                }
            }
        }

        ksort($sectionLines);
        ksort($configurationLines);
        $references = [];
        foreach ($sectionLines as $groups) {
            foreach ($groups as $group) {
                array_push($references, ...$group);
            }
        }
        foreach ($configurationLines as $lineReferences) {
            array_push($references, ...$lineReferences);
        }

        $unique = [];
        foreach ($references as $reference) {
            $key = $reference->kind->value."\0".$reference->name."\0".$reference->range->start->line."\0".$reference->range->start->character;
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    /** @return array<string, true> */
    private function nestedServices(YamlDocument $document, ?string $environment): array
    {
        $services = [];
        foreach ($document->mappings as $mapping) {
            if ($this->includes('base' === $mapping->scope ? null : substr($mapping->scope, \strlen('when@')), $environment)
                && !$mapping->isSequenceItem()
                && 2 === \count($mapping->path)
                && 'services' === $mapping->path[0]
                && '' === $mapping->value
            ) {
                $services[$mapping->scope."\0".$mapping->path[1]] = true;
            }
        }

        return $services;
    }

    /** @return list<DependencyInjectionReference> */
    private function serviceReferences(string $uri, string $text, string $line, int $lineOffset, ?string $environment): array
    {
        preg_match_all('/(?<![A-Za-z0-9_@])@(\?)?([.A-Za-z_\\\\][A-Za-z0-9_.\\\\:$-]*)/', $line, $matches, \PREG_OFFSET_CAPTURE);
        $references = [];
        foreach ($matches[2] as $index => [$name, $offset]) {
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Service,
                $name,
                $uri,
                $this->positionConverter->toRange($text, $lineOffset + $offset, \strlen($name)),
                '' !== $matches[1][$index][0],
                $environment,
            );
        }

        return $references;
    }

    /** @return list<DependencyInjectionReference> */
    private function parameterReferences(string $uri, string $text, string $line, int $lineOffset, ?string $environment): array
    {
        preg_match_all('/%([^%\s]+)%/', str_replace('%%', "\0\0", $line), $matches, \PREG_OFFSET_CAPTURE);
        $references = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (!str_starts_with($name, 'env(')) {
                $references[] = new DependencyInjectionReference(
                    DependencyInjectionSymbolKind::Parameter,
                    $name,
                    $uri,
                    $this->positionConverter->toRange($text, $lineOffset + $offset, \strlen($name)),
                    environment: $environment,
                );
            }
        }

        return $references;
    }

    /** @param array<string, true> $nestedServices */
    private function isUnprefixedAlias(YamlScalar $scalar, array $nestedServices): bool
    {
        if (null !== $scalar->tag
            || 3 !== \count($scalar->path)
            || 'services' !== $scalar->path[0]
            || str_starts_with($scalar->path[1], '_')
            || !\in_array($scalar->path[2], ['alias', 'decorates'], true)
        ) {
            return false;
        }
        $scope = null === $scalar->environment ? 'base' : 'when@'.$scalar->environment;
        $target = $this->scalar($scalar->raw);

        return isset($nestedServices[$scope."\0".$scalar->path[1]]) && '' !== ltrim($target, '@?') && !str_starts_with($target, '@');
    }

    private function includes(?string $scope, ?string $environment): bool
    {
        return null === $environment || null === $scope || $scope === $environment;
    }

    private function scalar(string $value): string
    {
        $value = trim($value);

        return \strlen($value) >= 2 && (("'" === $value[0] && str_ends_with($value, "'")) || ('"' === $value[0] && str_ends_with($value, '"')))
            ? substr($value, 1, -1)
            : $value;
    }
}
