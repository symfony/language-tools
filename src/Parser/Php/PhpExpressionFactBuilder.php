<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;

final class PhpExpressionFactBuilder
{
    public function __construct(
        private readonly TolerantPhpNodeAdapter $nodes,
        private readonly TolerantPhpScopeResolver $scopes,
    ) {
    }

    public function build(TolerantPhpNodeCollection $collection, string $source, PhpNameContext $names): TolerantPhpExpressionFacts
    {
        $methodCalls = [];
        foreach ($collection->methodCalls as $node) {
            $call = $this->methodCall($node, $source);
            if (null !== $call) {
                $methodCalls[] = $call;
            }
        }
        $objectCreations = [];
        foreach ($collection->objectCreations as $node) {
            $creation = $this->objectCreation($node, $source, $names);
            if (null !== $creation) {
                $objectCreations[] = $creation;
            }
        }
        $classReferences = [];
        foreach ($collection->classReferences as $node) {
            $reference = $this->classReference($node, $source, $names);
            if (null !== $reference) {
                $classReferences[] = $reference;
            }
        }

        return new TolerantPhpExpressionFacts($methodCalls, $objectCreations, $classReferences);
    }

    /**
     * @param array<Node|Token> $children
     *
     * @return list<PhpArgument>
     */
    public function arguments(array $children, string $source, ?PhpNameContext $names = null, ?ClassDeclaration $owner = null): array
    {
        $arguments = [];
        foreach ($children as $child) {
            if (!$child instanceof ArgumentExpression) {
                continue;
            }

            $name = $child->name?->getText($source);
            $expression = $child->expression?->getText($source);
            $arguments[] = new PhpArgument(
                \is_string($name) ? $name : null,
                $child->name?->getStartPosition(),
                $child->name?->getEndPosition(),
                $child->expression instanceof StringLiteral ? $this->stringLiteral($child->expression, $source) : null,
                null === $names ? null : $this->phpCallable($child->expression, $source, $names, $owner),
                \is_string($expression) ? $expression : null,
                $child->getStartPosition(),
                $child->getEndPosition(),
                $child->expression?->getStartPosition(),
                $child->expression?->getEndPosition(),
            );
        }

        return $arguments;
    }

    private function objectCreation(ObjectCreationExpression $creation, string $source, PhpNameContext $names): ?PhpObjectCreation
    {
        if (!$creation->classTypeDesignator instanceof QualifiedName) {
            return null;
        }
        $className = $this->scopes->className($creation->classTypeDesignator, $source, $names);
        if (null === $className) {
            return null;
        }
        $owner = $creation->getFirstAncestor(ClassDeclaration::class);
        $method = $creation->getFirstAncestor(MethodDeclaration::class);
        $methodName = $method instanceof MethodDeclaration && $method->name instanceof Token ? $method->name->getText($source) : null;

        return new PhpObjectCreation(
            $className,
            $this->arguments(
                $creation->argumentExpressionList->children ?? [],
                $source,
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

    private function methodCall(CallExpression $call, string $source): ?PhpMethodCall
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
            $this->arguments($call->argumentExpressionList->children ?? [], $source),
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
}
