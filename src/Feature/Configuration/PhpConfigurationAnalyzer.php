<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpConfigurationAnalyzer
{
    public function __construct(
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParserInterface $comments,
    ) {
    }

    /**
     * @return list<array{path: list<string>, argument: string, start: int, end: int}>
     */
    public function occurrences(string $source, ConfigurationIndex $index): array
    {
        $document = $this->parser->parse($source);
        $masked = $this->comments->mask($source);
        $callsByRange = [];
        foreach ($document->methodCalls as $call) {
            $callsByRange[$call->startOffset.':'.$call->endOffset] = $call;
        }

        /** @var array<int, array{path: list<string>, argument: string, start: int, end: int}|null> $resolved */
        $resolved = [];
        foreach ($document->methodCalls as $call) {
            $this->resolveCall($call, $source, $masked, $callsByRange, $resolved);
        }

        $occurrences = array_values(array_filter($resolved));
        usort($occurrences, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return array_values(array_filter($occurrences, static function (array $occurrence) use ($index): bool {
            if (!isset($index->roots()[$occurrence['path'][0]])) {
                return false;
            }
            for ($length = 2, $count = \count($occurrence['path']); $length < $count; ++$length) {
                if (null === $index->find(\array_slice($occurrence['path'], 0, $length))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    public function resolveNode(string $source, ConfigurationIndex $index, int $cursor): ?array
    {
        foreach ($this->occurrences($source, $index) as $occurrence) {
            if ($cursor < $occurrence['start'] || $cursor > $occurrence['end']) {
                continue;
            }
            $node = $index->find($occurrence['path']);

            return null === $node ? null : [$occurrence['path'], $node];
        }

        return null;
    }

    /** @return array{path: list<string>, prefix: string, start: int}|null */
    public function completionContext(string $source, int $cursor): ?array
    {
        $before = substr($this->comments->mask($source), 0, $cursor);
        if (1 !== preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\(\))*)->([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $match)) {
            return null;
        }
        $path = [$this->root($before, $match[1])];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(\)/', $match[2], $methods);
        foreach ($methods[1] as $method) {
            $path[] = $this->methodName($method);
        }
        $prefix = $match[3] ?? '';

        return ['path' => $path, 'prefix' => $prefix, 'start' => $cursor - \strlen($prefix)];
    }

    /**
     * @param array<string, PhpMethodCall>                                                       $callsByRange
     * @param array<int, array{path: list<string>, argument: string, start: int, end: int}|null> $resolved
     *
     * @return array{path: list<string>, argument: string, start: int, end: int}|null
     */
    private function resolveCall(PhpMethodCall $call, string $source, string $masked, array $callsByRange, array &$resolved): ?array
    {
        $id = spl_object_id($call);
        if (\array_key_exists($id, $resolved)) {
            return $resolved[$id];
        }
        $resolved[$id] = null;
        $methodRange = $this->methodRange($call, $source);
        if (null === $methodRange) {
            return null;
        }

        if (PhpMethodReceiverKind::Variable === $call->receiverContext->kind && null !== $call->receiverContext->name) {
            $path = [$this->root(substr($masked, 0, $call->receiverContext->startOffset + 1), $call->receiverContext->name)];
        } else {
            $receiver = $callsByRange[$call->receiverContext->startOffset.':'.$call->receiverContext->endOffset] ?? null;
            if (null === $receiver || null === $parent = $this->resolveCall($receiver, $source, $masked, $callsByRange, $resolved)) {
                return null;
            }
            $path = $parent['path'];
        }
        $path[] = $this->methodName($call->method);

        return $resolved[$id] = [
            'path' => $path,
            'argument' => $this->argument($call, $source, $masked, $methodRange[1]),
            'start' => $methodRange[0],
            'end' => $methodRange[1],
        ];
    }

    /** @return array{int, int}|null */
    private function methodRange(PhpMethodCall $call, string $source): ?array
    {
        $tail = substr($source, $call->receiverContext->endOffset, $call->endOffset - $call->receiverContext->endOffset);
        if (1 !== preg_match('/^\s*->\s*([A-Za-z_][A-Za-z0-9_]*)/', $tail, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $call->receiverContext->endOffset + $match[1][1];

        return [$start, $start + \strlen($match[1][0])];
    }

    private function argument(PhpMethodCall $call, string $source, string $masked, int $methodEnd): string
    {
        if ([] === $call->arguments) {
            return '';
        }
        if (1 === \count($call->arguments)) {
            $argument = $call->arguments[0];
            if (null !== $argument->expressionStartOffset && null !== $argument->expressionEndOffset && $argument->startOffset === $argument->expressionStartOffset) {
                return substr($masked, $argument->expressionStartOffset, $argument->expressionEndOffset - $argument->expressionStartOffset);
            }
        }
        $open = strpos($source, '(', $methodEnd);
        if (false === $open || $open >= $call->endOffset) {
            return '';
        }

        return substr($masked, $open + 1, max(0, $call->endOffset - $open - 2));
    }

    private function root(string $before, string $variable): string
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Config)\s+\$'.preg_quote($variable, '/').'\b/', $before, $matches);
        $class = end($matches[1]);
        if (false === $class) {
            return $this->methodName($variable);
        }
        $shortName = substr($class, (int) strrpos('\\'.$class, '\\'));

        return $this->methodName(substr($shortName, 0, -\strlen('Config')));
    }

    private function methodName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
