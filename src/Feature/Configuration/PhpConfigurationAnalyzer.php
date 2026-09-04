<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpConfigurationAnalyzer
{
    public function __construct(
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParser $comments,
    ) {
    }

    /** @return list<PhpConfigurationOccurrence> */
    public function occurrences(string $source, ConfigurationIndex $index): array
    {
        $document = $this->parser->parse($source);
        $masked = $this->comments->mask($source);
        $callsByRange = [];
        foreach ($document->methodCalls as $call) {
            $callsByRange[$call->startOffset.':'.$call->endOffset] = $call;
        }

        /** @var array<int, PhpConfigurationOccurrence|null> $resolved */
        $resolved = [];
        foreach ($document->methodCalls as $call) {
            $this->resolveCall($call, $source, $masked, $index, $callsByRange, $resolved);
        }

        $occurrences = array_values(array_filter($resolved));
        usort($occurrences, static fn (PhpConfigurationOccurrence $left, PhpConfigurationOccurrence $right): int => $left->startOffset <=> $right->startOffset);

        return array_values(array_filter($occurrences, static function (PhpConfigurationOccurrence $occurrence) use ($index): bool {
            if (!isset($index->roots()[$occurrence->schemaPath[0]])) {
                return false;
            }
            for ($length = 2, $count = \count($occurrence->schemaPath); $length < $count; ++$length) {
                if (null === $index->find(\array_slice($occurrence->schemaPath, 0, $length))) {
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
            if ($cursor < $occurrence->startOffset || $cursor > $occurrence->endOffset) {
                continue;
            }
            $node = $index->find($occurrence->schemaPath);

            return null === $node ? null : [$occurrence->path, $node];
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
     * @param array<string, PhpMethodCall>                $callsByRange
     * @param array<int, PhpConfigurationOccurrence|null> $resolved
     */
    private function resolveCall(PhpMethodCall $call, string $source, string $masked, ConfigurationIndex $index, array $callsByRange, array &$resolved): ?PhpConfigurationOccurrence
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
            $builderPath = $builderSchemaPath = [$this->root(substr($masked, 0, $call->receiverContext->startOffset + 1), $call->receiverContext->name)];
        } else {
            $receiver = $callsByRange[$call->receiverContext->startOffset.':'.$call->receiverContext->endOffset] ?? null;
            if (null === $receiver || null === $parent = $this->resolveCall($receiver, $source, $masked, $index, $callsByRange, $resolved)) {
                return null;
            }
            $builderPath = $parent->builderPath;
            $builderSchemaPath = $parent->builderSchemaPath;
        }
        $receiverNode = $index->find($builderSchemaPath);
        $method = $this->methodName($call->method);
        $keyedChild = $receiverNode?->keyedChild($method);
        $keyAttribute = $keyedChild?->keyAttribute();
        $keyArgument = null === $keyAttribute
            ? null
            : $call->namedOrPositionalArgument('' === $keyAttribute ? 'key' : $keyAttribute, 0);
        if (null !== $keyedChild && null !== $keyArgument) {
            $path = [...$builderPath, $method];
            $key = $keyArgument->stringLiteral?->value;
            if (null !== $key) {
                $path[] = $key;
            }
            $schemaPath = [...$builderSchemaPath, $keyedChild->name, $key ?? ''];
            $valueName = 'value' === $keyAttribute ? 'data' : 'value';
            $valueArgument = $call->namedOrPositionalArgument($valueName, 1);
            $argument = null === $valueArgument ? '' : $this->argumentValue($valueArgument, $masked);
        } else {
            $path = [...$builderPath, $method];
            $schemaPath = [...$builderSchemaPath, $method];
            $argument = $this->argument($call, $source, $masked, $methodRange[1]);
        }
        $node = $index->find($schemaPath);
        if (null !== $node && $this->returnsCurrentBuilder($node)) {
            $returnedBuilderPath = $builderPath;
            $returnedBuilderSchemaPath = $builderSchemaPath;
        } else {
            $returnedBuilderPath = $path;
            $returnedBuilderSchemaPath = $schemaPath;
        }

        return $resolved[$id] = new PhpConfigurationOccurrence(
            $path,
            $schemaPath,
            $returnedBuilderPath,
            $returnedBuilderSchemaPath,
            $argument,
            $methodRange[0],
            $methodRange[1],
        );
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
                return $this->argumentValue($argument, $masked);
            }
        }
        $open = strpos($source, '(', $methodEnd);
        if (false === $open || $open >= $call->endOffset) {
            return '';
        }

        return substr($masked, $open + 1, max(0, $call->endOffset - $open - 2));
    }

    private function argumentValue(PhpArgument $argument, string $masked): string
    {
        if (null === $argument->expressionStartOffset || null === $argument->expressionEndOffset) {
            return '';
        }

        return substr($masked, $argument->expressionStartOffset, $argument->expressionEndOffset - $argument->expressionStartOffset);
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

    private function returnsCurrentBuilder(ConfigurationNode $node): bool
    {
        if ('array' !== $node->type) {
            return true;
        }
        if (null === $node->prototype) {
            return false;
        }

        return 'array' !== $node->prototype->type
            || (null !== $node->prototype->prototype && 'array' !== $node->prototype->prototype->type);
    }

    private function methodName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
