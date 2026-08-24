<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class YamlDependencyInjectionExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    public function extract(string $uri, string $text, ?string $environment = null): DependencyInjectionSourceFacts
    {
        /** @var list<PendingServiceDeclaration> $services */
        $services = [];
        /** @var list<ParameterDeclaration> $parameters */
        $parameters = [];
        /** @var list<DependencyInjectionReference> $references */
        $references = [];
        $section = null;
        $sectionIndent = -1;
        $entryIndent = null;
        $currentService = null;
        $tagsIndent = null;
        $environmentSection = null;
        $scannedLines = [];
        $blockScalarLines = [];
        $blockScalarIndent = null;

        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$rawLine, $lineOffset]) {
            $line = rtrim($rawLine, "\r\n");
            $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
            $blockScalarContent = null !== $blockScalarIndent && ('' === trim($line) || $indent > $blockScalarIndent);
            if (!$blockScalarContent) {
                $blockScalarIndent = null;
            }
            if ('' === trim($line) || (!$blockScalarContent && str_starts_with(ltrim($line), '#'))) {
                continue;
            }
            if ($blockScalarContent) {
                $blockScalarLines[$lineOffset] = true;
            }

            $mapping = $blockScalarContent ? null : $this->mapping($line);
            if (null !== $mapping && 1 === preg_match('/^\s*[|>](?:[+-][1-9]?|[1-9][+-]?)?\s*(?:#.*)?$/', $mapping['rest'])) {
                $blockScalarIndent = $mapping['indent'];
            }
            if (null !== $mapping && 0 === $mapping['indent']) {
                $environmentSection = str_starts_with($mapping['key'], 'when@') ? substr($mapping['key'], \strlen('when@')) : null;
                if (null !== $environment && null !== $environmentSection && $environmentSection !== $environment) {
                    $section = null;
                    $currentService = null;
                    $tagsIndent = null;
                }
            }
            if (null !== $environment && null !== $environmentSection && $environmentSection !== $environment) {
                $scannedLines[$lineOffset] = true;
                continue;
            }
            if (null !== $mapping
                && \in_array($mapping['key'], ['parameters', 'services'], true)
                && '' === trim($mapping['rest'])
                && (0 === $mapping['indent'] || null !== $environmentSection)
            ) {
                $section = $mapping['key'];
                $sectionIndent = $mapping['indent'];
                $entryIndent = null;
                $currentService = null;
                $tagsIndent = null;

                continue;
            }

            if (null === $section || $indent <= $sectionIndent) {
                $section = null;
                $currentService = null;
                $tagsIndent = null;

                continue;
            }

            $scannedLines[$lineOffset] = true;
            array_push($references, ...$this->references($uri, $text, $line, $lineOffset, !$blockScalarContent));

            if (null === $mapping) {
                if (null !== $currentService && null !== $tagsIndent && $indent > $tagsIndent) {
                    $currentService->addTags($this->tags($line));
                }

                continue;
            }

            $entryIndent ??= $mapping['indent'];
            if ($mapping['indent'] === $entryIndent) {
                $tagsIndent = null;
                if ('parameters' === $section) {
                    $parameters[] = new ParameterDeclaration(
                        $mapping['key'],
                        $uri,
                        $this->range($text, $lineOffset + $mapping['keyOffset'], \strlen($mapping['key'])),
                    );
                    $currentService = null;

                    continue;
                }

                if (str_starts_with($mapping['key'], '_')) {
                    $currentService = null;

                    continue;
                }

                $currentService = new PendingServiceDeclaration(
                    $mapping['key'],
                    $this->range($text, $lineOffset + $mapping['keyOffset'], \strlen($mapping['key'])),
                );
                $services[] = $currentService;
                $target = $this->scalar($mapping['rest']);
                if (str_starts_with($target, '@')) {
                    $currentService->setAlias(ltrim(substr($target, 1), '?'));
                }

                continue;
            }

            if ('services' !== $section || null === $currentService || $mapping['indent'] <= $entryIndent) {
                continue;
            }

            if ('class' === $mapping['key']) {
                $className = $this->scalar($mapping['rest']);
                $currentService->setClassName('' !== $className ? ltrim($className, '\\') : null);
            } elseif (\in_array($mapping['key'], ['alias', 'decorates'], true)) {
                $target = $this->scalar($mapping['rest']);
                $normalizedTarget = ltrim($target, '@?');
                if ('' !== $normalizedTarget) {
                    if ('alias' === $mapping['key']) {
                        $currentService->setAlias($normalizedTarget);
                    } else {
                        $currentService->setDecorates($normalizedTarget);
                    }
                    if (!str_starts_with($target, '@')) {
                        $valueOffset = strpos($line, $target, $mapping['restOffset']);
                        if (false !== $valueOffset) {
                            $references[] = new DependencyInjectionReference(
                                DependencyInjectionSymbolKind::Service,
                                $normalizedTarget,
                                $uri,
                                $this->range($text, $lineOffset + $valueOffset, \strlen($normalizedTarget)),
                            );
                        }
                    }
                }
            } elseif ('tags' === $mapping['key']) {
                $tagsIndent = $mapping['indent'];
                $currentService->addTags($this->tags($mapping['rest']));
            } elseif (null !== $tagsIndent && $mapping['indent'] > $tagsIndent) {
                $currentService->addTags($this->tags($line));
            } else {
                $tagsIndent = null;
            }
        }

        $serviceDeclarations = [];
        foreach ($services as $service) {
            $serviceDeclarations[] = $service->declaration($uri);
        }

        // configuration files reference parameters outside the parameters
        // and services sections, as in framework or doctrine packages
        if (str_contains('/'.$uri, '/config/')) {
            foreach ($lines[0] as [$rawLine, $lineOffset]) {
                $line = rtrim($rawLine, "\r\n");
                if (isset($scannedLines[$lineOffset]) || '' === trim($line) || (!isset($blockScalarLines[$lineOffset]) && str_starts_with(ltrim($line), '#'))) {
                    continue;
                }
                array_push($references, ...$this->parameterReferences($uri, $text, $line, $lineOffset, !isset($blockScalarLines[$lineOffset])));
            }
        }

        return new DependencyInjectionSourceFacts(
            $uri,
            $serviceDeclarations,
            $parameters,
            $this->uniqueReferences($references),
        );
    }

    /**
     * @return array{indent: int, key: string, keyOffset: int, rest: string, restOffset: int}|null
     */
    private function mapping(string $line): ?array
    {
        if (preg_match(
            '/^(?<indent>[ \t]*)(?<quote>[\'\"])(?<key>.*?)\k<quote>\s*:(?<rest>.*)$/',
            $line,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return [
                'indent' => \strlen($matches['indent'][0]),
                'key' => $matches['key'][0],
                'keyOffset' => $matches['key'][1],
                'rest' => $matches['rest'][0],
                'restOffset' => $matches['rest'][1],
            ];
        }

        if (!preg_match(
            '/^(?<indent>[ \t]*)(?<key>[^:#][^:]*?)\s*:(?<rest>.*)$/',
            $line,
            $matches,
            \PREG_OFFSET_CAPTURE,
        )) {
            return null;
        }

        $key = trim($matches['key'][0]);
        $leadingWhitespace = \strlen($matches['key'][0]) - \strlen(ltrim($matches['key'][0]));

        return [
            'indent' => \strlen($matches['indent'][0]),
            'key' => $key,
            'keyOffset' => $matches['key'][1] + $leadingWhitespace,
            'rest' => $matches['rest'][0],
            'restOffset' => $matches['rest'][1],
        ];
    }

    /** @return list<DependencyInjectionReference> */
    private function references(string $uri, string $text, string $line, int $lineOffset, bool $maskComment): array
    {
        if ($maskComment) {
            $line = $this->maskComment($line);
        }
        $references = [];
        preg_match_all(
            '/(?<![A-Za-z0-9_@])@(\?)?([.A-Za-z_\\\\][A-Za-z0-9_.\\\\:$-]*)/',
            $line,
            $serviceMatches,
            \PREG_OFFSET_CAPTURE,
        );
        foreach ($serviceMatches[2] as $index => [$name, $offset]) {
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Service,
                $name,
                $uri,
                $this->range($text, $lineOffset + $offset, \strlen($name)),
                '' !== $serviceMatches[1][$index][0],
            );
        }

        return [...$references, ...$this->parameterReferences($uri, $text, $line, $lineOffset, false)];
    }

    /** @return list<DependencyInjectionReference> */
    private function parameterReferences(string $uri, string $text, string $line, int $lineOffset, bool $maskComment = true): array
    {
        if ($maskComment) {
            $line = $this->maskComment($line);
        }
        $references = [];
        // %% escapes a literal percent, as in monolog line formats
        preg_match_all('/%([^%\s]+)%/', str_replace('%%', "\0\0", $line), $parameterMatches, \PREG_OFFSET_CAPTURE);
        foreach ($parameterMatches[1] as [$name, $offset]) {
            if (str_starts_with($name, 'env(')) {
                continue;
            }

            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Parameter,
                $name,
                $uri,
                $this->range($text, $lineOffset + $offset, \strlen($name)),
            );
        }

        return $references;
    }

    private function maskComment(string $line): string
    {
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($line); $offset < $length; ++$offset) {
            $character = $line[$offset];
            if (null !== $quote) {
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                } elseif ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ("'" === $quote && "'" === ($line[$offset + 1] ?? null)) {
                        ++$offset;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif ('#' === $character && (0 === $offset || ctype_space($line[$offset - 1]))) {
                return substr($line, 0, $offset).str_repeat(' ', $length - $offset);
            }
        }

        return $line;
    }

    private function scalar(string $value): string
    {
        $value = trim($this->maskComment($value));
        if (\strlen($value) >= 2 && (("'" === $value[0] && str_ends_with($value, "'")) || ('"' === $value[0] && str_ends_with($value, '"')))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /** @return list<string> */
    private function tags(string $value): array
    {
        $tags = [];
        preg_match_all('/(?:\bname\s*:\s*)?[\'\"]?([A-Za-z_][A-Za-z0-9_.-]*)[\'\"]?/', $value, $matches);
        foreach ($matches[1] as $tag) {
            if (!\in_array($tag, ['name', 'priority'], true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param list<DependencyInjectionReference> $references
     *
     * @return list<DependencyInjectionReference>
     */
    private function uniqueReferences(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            $key = $reference->kind()->value.'\0'.$reference->name().'\0'
                .$reference->range()->start()->line().'\0'.$reference->range()->start()->character();
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range(
            $this->positionConverter->toPosition($text, $offset),
            $this->positionConverter->toPosition($text, $offset + $length),
        );
    }
}

final class PendingServiceDeclaration
{
    private ?string $className = null;
    private ?string $alias = null;
    private ?string $decorates = null;

    /** @var list<string> */
    private array $tags = [];

    public function __construct(
        private readonly string $id,
        private readonly Range $range,
    ) {
    }

    public function setClassName(?string $className): void
    {
        $this->className = $className;
    }

    public function setAlias(string $alias): void
    {
        $this->alias = $alias;
    }

    public function setDecorates(string $decorates): void
    {
        $this->decorates = $decorates;
    }

    /** @param list<string> $tags */
    public function addTags(array $tags): void
    {
        $this->tags = [...$this->tags, ...$tags];
    }

    public function declaration(string $uri): ServiceDeclaration
    {
        return new ServiceDeclaration(
            $this->id,
            $uri,
            $this->range,
            $this->className ?? (str_contains($this->id, '\\') ? ltrim($this->id, '\\') : null),
            $this->alias,
            $this->decorates,
            array_values(array_unique($this->tags)),
        );
    }
}
