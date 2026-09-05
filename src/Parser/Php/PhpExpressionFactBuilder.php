<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ParenthesizedExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\UnaryOpExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\ReservedWord;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

final class PhpExpressionFactBuilder
{
    public function __construct(
        private readonly TolerantPhpNodeAdapter $nodes,
        private readonly TolerantPhpScopeResolver $scopes,
    ) {
    }

    /**
     * Resolves every `X::class` reference once, keyed by node object id.
     *
     * @return array<int, PhpClassReference>
     */
    public function classReferences(TolerantPhpNodeCollection $collection, string $source, PhpNameContext $names): array
    {
        $references = [];
        foreach ($collection->classReferences as $node) {
            $reference = $this->classReference($node, $source, $names);
            if (null !== $reference) {
                $references[spl_object_id($node)] = $reference;
            }
        }

        return $references;
    }

    /** @param array<int, PhpClassReference> $classReferencesByNode */
    public function build(TolerantPhpNodeCollection $collection, string $source, PhpNameContext $names, array $classReferencesByNode): TolerantPhpExpressionFacts
    {
        $methodCalls = [];
        foreach ($collection->methodCalls as $node) {
            $call = $this->methodCall($node, $source, $classReferencesByNode);
            if (null !== $call) {
                $methodCalls[] = $call;
            }
        }
        $objectCreations = [];
        foreach ($collection->objectCreations as $node) {
            $creation = $this->objectCreation($node, $source, $names, $classReferencesByNode);
            if (null !== $creation) {
                $objectCreations[] = $creation;
            }
        }

        return new TolerantPhpExpressionFacts($methodCalls, $objectCreations, array_values($classReferencesByNode));
    }

    /**
     * @param array<Node|Token>             $children
     * @param array<int, PhpClassReference> $classReferencesByNode
     *
     * @return list<PhpArgument>
     */
    public function arguments(array $children, string $source, array $classReferencesByNode, ?PhpNameContext $names = null, ?ClassDeclaration $owner = null): array
    {
        $arguments = [];
        foreach ($children as $child) {
            if (!$child instanceof ArgumentExpression) {
                continue;
            }

            $name = $child->name?->getText($source);
            $expression = $child->expression?->getText($source);
            $stringLiteral = $child->expression instanceof StringLiteral ? $this->stringLiteral($child->expression, $source) : null;
            $literal = null;
            if ($this->argumentListComplete($child)) {
                $literal = $child->expression instanceof StringLiteral
                    ? (null === $stringLiteral ? null : new PhpLiteral(PhpLiteralKind::String, $stringLiteral->value))
                    : $this->literal($child->expression, $source);
            }
            $arguments[] = new PhpArgument(
                \is_string($name) ? $name : null,
                $child->name?->getStartPosition(),
                $child->name?->getEndPosition(),
                $stringLiteral,
                $literal,
                null === $names ? null : $this->phpCallable($child->expression, $source, $names, $owner),
                \is_string($expression) ? $expression : null,
                $child->getStartPosition(),
                $child->getEndPosition(),
                $child->expression?->getStartPosition(),
                $child->expression?->getEndPosition(),
                $child->dotDotDotToken instanceof Token,
                $this->completeClassReference($child, $classReferencesByNode),
            );
        }

        return $arguments;
    }

    /** @param array<int, PhpClassReference> $classReferencesByNode */
    private function completeClassReference(ArgumentExpression $argument, array $classReferencesByNode): ?PhpClassReference
    {
        $expression = $argument->expression;
        if ($argument->dotDotDotToken instanceof Token || !$expression instanceof Node) {
            return null;
        }

        return $classReferencesByNode[spl_object_id($expression)] ?? null;
    }

    private function argumentListComplete(ArgumentExpression $argument): bool
    {
        $owner = $argument->getParent()?->getParent();
        if ($owner instanceof CallExpression) {
            return !$owner->closeParen instanceof MissingToken;
        }
        if ($owner instanceof ObjectCreationExpression || $owner instanceof Attribute) {
            return null === $owner->openParen || ($owner->closeParen instanceof Token && !$owner->closeParen instanceof MissingToken);
        }

        return true;
    }

    /** @param array<int, PhpClassReference> $classReferencesByNode */
    private function objectCreation(ObjectCreationExpression $creation, string $source, PhpNameContext $names, array $classReferencesByNode): ?PhpObjectCreation
    {
        if (!$creation->classTypeDesignator instanceof QualifiedName) {
            return null;
        }
        $className = $this->scopes->className($creation->classTypeDesignator, $source, $names);
        if (null === $className) {
            return null;
        }
        [$owner] = $this->scopes->enclosingContext($creation);
        $method = $creation->getFirstAncestor(MethodDeclaration::class);
        $methodName = $method instanceof MethodDeclaration && $method->name instanceof Token ? $method->name->getText($source) : null;

        return new PhpObjectCreation(
            $className,
            $this->arguments(
                $creation->argumentExpressionList->children ?? [],
                $source,
                $classReferencesByNode,
                $names,
                $owner instanceof ClassDeclaration ? $owner : null,
            ),
            $creation->getStartPosition(),
            $creation->getEndPosition(),
            $creation->classTypeDesignator->getStartPosition(),
            $creation->classTypeDesignator->getEndPosition(),
            \is_string($methodName) && '' !== $methodName ? $methodName : null,
        );
    }

    /** @param array<int, PhpClassReference> $classReferencesByNode */
    private function methodCall(CallExpression $call, string $source, array $classReferencesByNode): ?PhpMethodCall
    {
        $memberAccess = $call->callableExpression;
        if (!$memberAccess instanceof MemberAccessExpression) {
            return null;
        }
        $receiverNode = $memberAccess->dereferencableExpression;
        $receiver = $receiverNode->getText($source);
        $methodNode = $memberAccess->memberName;
        $method = $methodNode->getText($source);
        if (!\is_string($method)) {
            return null;
        }
        [$owner, $scope] = $this->scopes->enclosingContext($call);
        $methodName = $scope instanceof MethodDeclaration && $scope->name instanceof Token ? $scope->name->getText($source) : null;

        return new PhpMethodCall(
            $receiver,
            $this->methodReceiver($receiverNode, $source),
            $method,
            $call->getStartPosition(),
            $call->getEndPosition(),
            $methodNode->getStartPosition(),
            $methodNode->getEndPosition(),
            $this->arguments($call->argumentExpressionList->children ?? [], $source, $classReferencesByNode),
            null === $owner ? null : (string) $owner->getNamespacedName(),
            \is_string($methodName) && '' !== $methodName ? $methodName : null,
            $scope?->getStartPosition(),
        );
    }

    private function methodReceiver(Node|Token $receiver, string $source): PhpMethodReceiver
    {
        $kind = PhpMethodReceiverKind::Other;
        $name = null;
        if ($receiver instanceof Variable) {
            $raw = $receiver->getText($source);
            if ('$this' === $raw) {
                $kind = PhpMethodReceiverKind::This;
            } elseif (null !== $name = $this->scopes->variableName($receiver, $source)) {
                $kind = PhpMethodReceiverKind::Variable;
            }
        } elseif ($receiver instanceof MemberAccessExpression && $receiver->dereferencableExpression instanceof Variable && '$this' === $receiver->dereferencableExpression->getText($source)) {
            $member = $receiver->memberName->getText($source);
            if (\is_string($member) && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $member)) {
                $kind = PhpMethodReceiverKind::ThisProperty;
                $name = $member;
            }
        }

        return new PhpMethodReceiver($kind, $name, $receiver->getStartPosition(), $receiver->getEndPosition());
    }

    private function classReference(ScopedPropertyAccessExpression $reference, string $source, PhpNameContext $names): ?PhpClassReference
    {
        $className = $this->scopes->classReferenceName($reference, $source, $names);
        $qualifier = $reference->scopeResolutionQualifier;
        if (null === $className || !$qualifier instanceof QualifiedName) {
            return null;
        }

        return new PhpClassReference($className, $qualifier->getStartPosition(), $qualifier->getEndPosition());
    }

    private function phpCallable(mixed $expression, string $source, PhpNameContext $names, ?ClassDeclaration $owner): ?PhpCallable
    {
        if ($expression instanceof ArrayCreationExpression) {
            $values = array_map(static fn ($element): mixed => $element->elementValue, $this->nodes->arrayElements($expression));
            if (2 !== \count($values) || !$values[1] instanceof StringLiteral) {
                return null;
            }

            return $this->callable(
                $this->scopes->classNameFromExpression($values[0], $source, $names, $owner),
                $this->stringLiteral($values[1], $source)?->value,
            );
        }
        if (!$expression instanceof CallExpression || !$this->isFirstClassCallable($expression)) {
            return null;
        }
        $callable = $expression->callableExpression;
        if ($callable instanceof ScopedPropertyAccessExpression) {
            $className = $this->scopes->classNameFromExpression($callable->scopeResolutionQualifier, $source, $names, $owner);
            $method = $callable->memberName->getText($source);
        } elseif ($callable instanceof MemberAccessExpression) {
            $className = $this->scopes->classNameFromExpression($callable->dereferencableExpression, $source, $names, $owner);
            $method = $callable->memberName->getText($source);
        } else {
            return null;
        }

        return $this->callable($className, \is_string($method) ? $method : null);
    }

    private function callable(?string $className, ?string $method): ?PhpCallable
    {
        if (null === $className || '' === $className || null === $method || 1 !== preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $method)) {
            return null;
        }

        return new PhpCallable($className, $method);
    }

    private function isFirstClassCallable(CallExpression $call): bool
    {
        $arguments = [];
        foreach ($call->argumentExpressionList->children ?? [] as $child) {
            if ($child instanceof ArgumentExpression) {
                $arguments[] = $child;
            }
        }

        return 1 === \count($arguments) && $arguments[0]->dotDotDotToken instanceof Token && null === $arguments[0]->expression;
    }

    private function stringLiteral(StringLiteral $literal, string $source): ?PhpStringLiteral
    {
        $children = \is_array($literal->children) ? $literal->children : [$literal->children];
        foreach ($children as $child) {
            if (!$child instanceof Token) {
                return null;
            }
        }

        $start = $literal->getStartPosition();
        $end = $literal->getEndPosition();
        $text = substr($source, $start, $end - $start);
        if (\strlen($text) < 2 || !\in_array($text[0], ["'", '"'], true) || !str_ends_with($text, $text[0])) {
            return null;
        }

        return new PhpStringLiteral(PhpStringLiteralDecoder::decode($text[0], substr($text, 1, -1)), $start + 1, $end - 1);
    }

    private function literal(mixed $expression, string $source): ?PhpLiteral
    {
        if ($expression instanceof StringLiteral) {
            $literal = $this->stringLiteral($expression, $source);

            return null === $literal ? null : new PhpLiteral(PhpLiteralKind::String, $literal->value);
        }
        if ($expression instanceof NumericLiteral) {
            return $this->numericLiteral($expression, $source);
        }
        if ($expression instanceof ReservedWord) {
            return match ($expression->children->kind) {
                TokenKind::TrueReservedWord => new PhpLiteral(PhpLiteralKind::Boolean, true),
                TokenKind::FalseReservedWord => new PhpLiteral(PhpLiteralKind::Boolean, false),
                TokenKind::NullReservedWord => new PhpLiteral(PhpLiteralKind::Null),
                default => null,
            };
        }
        if ($expression instanceof ArrayCreationExpression) {
            return $expression->closeParenOrBracket instanceof MissingToken ? null : new PhpLiteral(PhpLiteralKind::Array);
        }
        if ($expression instanceof ParenthesizedExpression) {
            return $expression->closeParen instanceof MissingToken ? null : $this->literal($expression->expression, $source);
        }
        if (!$expression instanceof UnaryOpExpression
            || !\in_array($expression->operator->kind, [TokenKind::PlusToken, TokenKind::MinusToken], true)
        ) {
            return null;
        }
        $literal = $this->literal($expression->operand, $source);
        if (null === $literal
            || !\in_array($literal->kind, [PhpLiteralKind::Integer, PhpLiteralKind::Float], true)
            || (!\is_int($literal->scalarValue) && !\is_float($literal->scalarValue))
        ) {
            return null;
        }

        $value = TokenKind::MinusToken === $expression->operator->kind ? -$literal->scalarValue : $literal->scalarValue;

        return new PhpLiteral(\is_int($value) ? PhpLiteralKind::Integer : PhpLiteralKind::Float, $value);
    }

    private function numericLiteral(NumericLiteral $literal, string $source): ?PhpLiteral
    {
        $text = strtolower(str_replace('_', '', (string) $literal->children->getText($source)));
        if (TokenKind::IntegerLiteralToken === $literal->children->kind
            && 1 < \strlen($text)
            && '0' === $text[0]
            && !str_starts_with($text, '0x')
            && !str_starts_with($text, '0b')
            && !str_starts_with($text, '0o')
            && \strlen($text) !== strspn($text, '01234567')
        ) {
            return null;
        }

        return match ($literal->children->kind) {
            TokenKind::IntegerLiteralToken => new PhpLiteral(
                PhpLiteralKind::Integer,
                str_starts_with($text, '0o') ? \intval(substr($text, 2), 8) : \intval($text, 0),
            ),
            TokenKind::FloatingLiteralToken => new PhpLiteral(PhpLiteralKind::Float, $this->floatingLiteral($text)),
            default => null,
        };
    }

    private function floatingLiteral(string $text): float
    {
        if (str_starts_with($text, '0x')) {
            return (float) hexdec(substr($text, 2));
        }
        if (str_starts_with($text, '0b')) {
            return (float) bindec(substr($text, 2));
        }
        if (str_starts_with($text, '0o')) {
            return (float) octdec(substr($text, 2));
        }
        if (1 < \strlen($text) && '0' === $text[0] && \strlen($text) === strspn($text, '01234567')) {
            return (float) octdec($text);
        }

        return (float) $text;
    }
}
