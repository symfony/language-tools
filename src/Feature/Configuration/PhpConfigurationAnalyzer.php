<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;
use Symfony\Lsp\Parser\SourceComment;

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
        $callsByRange = $this->callsByRange($document);

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
        $masked = $this->comments->mask($source);
        $comments = $this->comments->comments($source);
        if ($cursor < 0 || $cursor > \strlen($masked) || $this->withinComment($comments, $cursor)) {
            return null;
        }
        $blanks = array_column($comments, 'startOffset', 'endOffset');
        preg_match('/[A-Za-z_][A-Za-z0-9_]*$/D', substr($masked, 0, $cursor), $match);
        $prefix = $match[0] ?? '';
        $arrow = $this->skipBlanks($masked, $blanks, $cursor - \strlen($prefix));
        if ($arrow < 2 || '->' !== substr($masked, $arrow - 2, 2)) {
            return null;
        }
        $nullsafe = $arrow > 2 && '?' === $masked[$arrow - 3];
        $path = $this->receiverPath($source, $masked, $index, $this->skipBlanks($masked, $blanks, $arrow - ($nullsafe ? 3 : 2)));

        return null === $path ? null : ['path' => $path, 'prefix' => $prefix, 'start' => $cursor - \strlen($prefix)];
    }

    /**
     * The path the completed part of the chain resolves to, from parser facts
     * when its receiver is a call, from the receiver variable otherwise.
     *
     * @return list<string>|null
     */
    private function receiverPath(string $source, string $masked, ConfigurationIndex $index, int $receiverEnd): ?array
    {
        $document = $this->parser->parse($source);
        foreach ($document->methodCalls as $call) {
            if ($receiverEnd !== $call->endOffset) {
                continue;
            }
            /** @var array<int, PhpConfigurationOccurrence|null> $resolved */
            $resolved = [];

            return $this->resolveCall($call, $document, $index, $this->callsByRange($document), $resolved)?->builderSchemaPath;
        }
        if (1 !== preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)$/D', substr($masked, 0, $receiverEnd), $match)) {
            return null;
        }
        $root = $this->variableRoot($this->declaredVariables($document, $match[1], $receiverEnd), $match[1], $index);

        return null === $root ? null : [$root];
    }

    /** @param list<SourceComment> $comments */
    private function withinComment(array $comments, int $offset): bool
    {
        foreach ($comments as $comment) {
            // a line comment still holds the cursor at its end, a block comment ends after its delimiter
            if ($offset > $comment->startOffset && ($offset < $comment->endOffset || $offset <= $comment->contentEndOffset)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, int> $blanks comment start offsets, keyed by end offset */
    private function skipBlanks(string $masked, array $blanks, int $offset): int
    {
        while ($offset > 0) {
            if (isset($blanks[$offset])) {
                $offset = $blanks[$offset];
            } elseif (ctype_space($masked[$offset - 1])) {
                --$offset;
            } else {
                break;
            }
        }

        return $offset;
    }

    /**
     * The declarations a plain variable receiver at the offset can resolve to,
     * taking the innermost scope holding the offset.
     *
     * @return list<PhpTypedVariable>
     */
    private function declaredVariables(PhpDocument $document, string $name, int $offset): array
    {
        $innermost = null;
        foreach ($document->typedVariables as $variable) {
            if ($name !== $variable->name
                || !\in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                || null === $variable->scopeStartOffset
                || null === $variable->scopeEndOffset
                || $offset < $variable->scopeStartOffset
                || $offset > $variable->scopeEndOffset
                || (null !== $innermost && $variable->scopeStartOffset < $innermost->scopeStartOffset)
            ) {
                continue;
            }
            $innermost = $variable;
        }

        return null === $innermost ? [] : [$innermost];
    }

    /** @return array<string, PhpMethodCall> */
    private function callsByRange(PhpDocument $document): array
    {
        $callsByRange = [];
        foreach ($document->methodCalls as $call) {
            $callsByRange[$call->startOffset.':'.$call->endOffset] = $call;
        }

        return $callsByRange;
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
            $variables = $document->receiverVariables($call);
            if ([] === $variables && [] !== $this->declaredVariables($document, $call->receiverContext->name, $call->methodStartOffset)) {
                return null;
            }
            $root = $this->variableRoot($variables, $call->receiverContext->name, $index);
            if (null === $root) {
                return null;
            }
            $builderPath = $builderSchemaPath = [$root];
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

    /**
     * The root of a variable receiver, from its declared builder type, or from
     * its name when it has no declared type. A variable declared with another
     * type isn't a configuration builder at all.
     *
     * @param list<PhpTypedVariable> $variables
     */
    private function variableRoot(array $variables, string $name, ConfigurationIndex $index): ?string
    {
        foreach ($variables as $variable) {
            foreach ($variable->types as $type) {
                $rootName = $this->builderRootName($type);
                if (null !== $rootName) {
                    return $this->matchingRoot($rootName, $index);
                }
            }
        }

        return [] === $variables ? $this->matchingRoot($name, $index) : null;
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
