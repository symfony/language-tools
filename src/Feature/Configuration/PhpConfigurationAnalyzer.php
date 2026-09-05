<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
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
        $callsByRange = [];
        foreach ($document->methodCalls as $call) {
            $callsByRange[$call->startOffset.':'.$call->endOffset] = $call;
        }

        /** @var array<int, PhpConfigurationOccurrence|null> $resolved */
        $resolved = [];
        foreach ($document->methodCalls as $call) {
            $this->resolveCall($call, $document, $index, $callsByRange, $resolved);
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
    public function completionContext(string $source, ConfigurationIndex $index, int $cursor): ?array
    {
        $before = substr($this->comments->mask($source), 0, $cursor);
        if (1 !== preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\(\))*)->([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $match)) {
            return null;
        }
        $path = [$this->lexicalRoot($before, $match[1], $index)];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(\)/', $match[2], $methods);
        foreach ($methods[1] as $method) {
            $path[] = $this->configurationName($method, $index->find($path));
        }
        $prefix = $match[3] ?? '';

        return ['path' => $path, 'prefix' => $prefix, 'start' => $cursor - \strlen($prefix)];
    }

    /**
     * @param array<string, PhpMethodCall>                $callsByRange
     * @param array<int, PhpConfigurationOccurrence|null> $resolved
     */
    private function resolveCall(PhpMethodCall $call, PhpDocument $document, ConfigurationIndex $index, array $callsByRange, array &$resolved): ?PhpConfigurationOccurrence
    {
        $id = spl_object_id($call);
        if (\array_key_exists($id, $resolved)) {
            return $resolved[$id];
        }
        $resolved[$id] = null;
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $call->method)) {
            return null;
        }

        if (PhpMethodReceiverKind::Variable === $call->receiverContext->kind && null !== $call->receiverContext->name) {
            $builderPath = $builderSchemaPath = [$this->receiverRoot($call, $document, $index)];
        } else {
            $receiver = $callsByRange[$call->receiverContext->startOffset.':'.$call->receiverContext->endOffset] ?? null;
            if (null === $receiver || null === $parent = $this->resolveCall($receiver, $document, $index, $callsByRange, $resolved)) {
                return null;
            }
            $builderPath = $parent->builderPath;
            $builderSchemaPath = $parent->builderSchemaPath;
        }
        $receiverNode = $index->find($builderSchemaPath);
        $method = $this->configurationName($call->method, $receiverNode);
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
            $literal = $call->namedOrPositionalArgument($valueName, 1)?->completeLiteral;
        } else {
            $path = [...$builderPath, $method];
            $schemaPath = [...$builderSchemaPath, $method];
            $literal = 1 === \count($call->arguments) ? $call->arguments[0]->completeLiteral : null;
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
            $literal,
            $call->methodStartOffset,
            $call->methodEndOffset,
        );
    }

    private function receiverRoot(PhpMethodCall $call, PhpDocument $document, ConfigurationIndex $index): string
    {
        foreach ($document->receiverVariables($call) as $variable) {
            foreach ($variable->types as $type) {
                $name = $this->builderRootName($type);
                if (null !== $name) {
                    return $this->matchingRoot($name, $index);
                }
            }
        }

        return $this->matchingRoot((string) $call->receiverContext->name, $index);
    }

    private function lexicalRoot(string $before, string $variable, ConfigurationIndex $index): string
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Config)\s+\$'.preg_quote($variable, '/').'\b/', $before, $matches);
        $class = end($matches[1]);

        return $this->matchingRoot(false === $class ? $variable : ($this->builderRootName($class) ?? $variable), $index);
    }

    private function builderRootName(string $class): ?string
    {
        $shortName = substr($class, (int) strrpos('\\'.$class, '\\'));
        if (!str_ends_with($shortName, 'Config')) {
            return null;
        }
        $name = substr($shortName, 0, -\strlen('Config'));

        return '' === $name ? null : $name;
    }

    private function matchingRoot(string $name, ConfigurationIndex $index): string
    {
        foreach (array_keys($index->roots()) as $root) {
            if (lcfirst($name) === ConfigurationNode::phpMethodName($root)) {
                return $root;
            }
        }

        return $this->inferredConfigurationName($name);
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

    private function configurationName(string $method, ?ConfigurationNode $parent): string
    {
        if (null !== $parent) {
            $candidate = [] !== $parent->children ? $parent : $parent->prototype;
            foreach ($candidate?->childNames() ?? [] as $name) {
                if ($method === ConfigurationNode::phpMethodName($name)) {
                    return $name;
                }
            }
        }

        return $this->inferredConfigurationName($method);
    }

    private function inferredConfigurationName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
