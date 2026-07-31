<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class EnvironmentProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    private const ARGUMENT_PROCESSORS = ['default', 'enum', 'key'];
    private const BUILT_IN_PROCESSORS = ['base64', 'bool', 'const', 'csv', 'default', 'defined', 'enum', 'file', 'float', 'int', 'json', 'key', 'not', 'query_string', 'require', 'resolve', 'shuffle', 'string', 'trim', 'url', 'urlencode'];

    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly EnvironmentIndexRegistry $indexes,
        private readonly EnvironmentExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $cursor = $this->converter->toByteOffset($document->text(), $position);
        if (!preg_match('/%env\(([^)]*)$/', substr($document->text(), 0, $cursor), $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $expression = $match[1][0];
        $separator = strrpos($expression, ':');
        $prefix = false === $separator ? $expression : substr($expression, $separator + 1);
        $start = $cursor - \strlen($prefix);
        $end = $this->converter->toPosition($document->text(), $cursor + strspn(substr($document->text(), $cursor), 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_'));
        $items = [];
        $index = $this->indexes->forProject($project);
        foreach ($index->processors() as $name => $type) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->item($name, $name.':', 'Environment processor returning '.$type, $document->text(), $start, $end);
            }
        }
        foreach ($index->names() as $name) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->item($name, $name, 'Environment variable', $document->text(), $start, $end);
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
        [$reference, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $details = [\sprintf('Environment variable: `%s`', $reference->name())];
        if ([] !== $reference->processors()) {
            $details[] = \sprintf('Processors: `%s`', implode('`, `', $reference->processors()));
            foreach ($reference->processors() as $processor) {
                if (isset($index->processors()[$processor])) {
                    $details[] = \sprintf('Expected type: `%s`', $index->processors()[$processor]);
                    break;
                }
            }
        }
        foreach ($index->declarations($reference->name()) as $declaration) {
            $details[] = \sprintf('Declared in: `%s`', $declaration->uri());
            $details[] = 'Default present: '.($declaration->hasDefault() ? 'yes' : 'no');
        }

        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;

        return array_map(fn (EnvironmentDeclaration $declaration): array => ['uri' => $declaration->uri(), 'range' => $this->range($declaration->range())], $this->indexes->forProject($project)->declarations($reference->name()));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;

        return array_map(fn (EnvironmentReference $item): array => ['uri' => $item->uri(), 'range' => $this->range($item->range())], $this->indexes->forProject($project)->references($reference->name()));
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        if (null === $document) {
            return null;
        }
        $project = $this->projects->forDocumentUri($document->uri());
        if (null === $project) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $processors = $index->processors();
        $diagnostics = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->references() as $reference) {
            $skipNext = false;
            $previousProcessor = null;
            foreach ($reference->processors() as $processor) {
                if ($skipNext) {
                    $skipNext = false;
                    $previousProcessor = null;
                    continue;
                }
                if ('' === $processor) {
                    $diagnostics[] = ['range' => $this->range($reference->range()), 'severity' => 1, 'source' => 'symfony', 'code' => 'env.malformed_chain', 'message' => 'Environment processor chains cannot contain empty segments.'];
                    continue;
                }
                if (\in_array($processor, self::ARGUMENT_PROCESSORS, true)) {
                    $skipNext = true;
                }
                $customProcessorArgument = null !== $previousProcessor && isset($processors[$previousProcessor]) && !\in_array($previousProcessor, self::BUILT_IN_PROCESSORS, true);
                if ($index->processorsComplete() && !$customProcessorArgument && !isset($processors[$processor])) {
                    $diagnostics[] = ['range' => $this->range($reference->range()), 'severity' => 1, 'source' => 'symfony', 'code' => 'env.unknown_processor', 'message' => \sprintf('Environment processor "%s" is not installed.', $processor)];
                }
                $previousProcessor = $processor;
            }
        }
        preg_match_all('/%env\([^\)\r\n]*%/', $document->text(), $malformed, \PREG_OFFSET_CAPTURE);
        foreach ($malformed[0] as [$expression, $offset]) {
            $range = new Range($this->converter->toPosition($document->text(), $offset), $this->converter->toPosition($document->text(), $offset + \strlen($expression)));
            $diagnostics[] = ['range' => $this->range($range), 'severity' => 1, 'source' => 'symfony', 'code' => 'env.malformed_chain', 'message' => 'Malformed environment expression; expected ")%".'];
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{EnvironmentReference, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $facts = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
        foreach ($facts->declarations() as $declaration) {
            if ($this->contains($document->text(), $declaration->range(), $offset)) {
                return [new EnvironmentReference($declaration->name(), $document->uri(), $declaration->range(), []), $project];
            }
        }
        foreach ($facts->references() as $reference) {
            if ($this->contains($document->text(), $reference->range(), $offset)) {
                return [$reference, $project];
            }
        }

        return null;
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }

    /** @return array<array-key, mixed> */
    private function item(string $label, string $newText, string $detail, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $label, 'kind' => 12, 'detail' => $detail, 'textEdit' => ['range' => ['start' => ['line' => $position->line(), 'character' => $position->character()], 'end' => ['line' => $end->line(), 'character' => $end->character()]], 'newText' => $newText]];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
