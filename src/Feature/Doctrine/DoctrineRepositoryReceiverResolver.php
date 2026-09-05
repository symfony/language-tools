<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;

final class DoctrineRepositoryReceiverResolver
{
    public function __construct(private readonly PhpCapturedReceiverResolver $capturedReceivers)
    {
    }

    /**
     * @param list<PhpMethodCall> $calls
     * @param array<string, true> $localRepositoryClasses
     *
     * @return array<int, array{entityClass: ?string, repositoryClass: ?string}>
     */
    public function resolveCalls(string $source, PhpDocument $php, array $calls, array $localRepositoryClasses): array
    {
        $assignments = $this->repositoryAssignmentEntities($source, $php);
        $receivers = [];
        foreach ($calls as $call) {
            $receiver = $this->resolve($source, $php, $call, $localRepositoryClasses, $assignments);
            if (null !== $receiver) {
                $receivers[spl_object_id($call)] = $receiver;
            }
        }

        return $receivers;
    }

    /**
     * @param array<string, true> $localRepositoryClasses
     *
     * @return array{entityClass: ?string, repositoryClass: ?string}|null
     */
    public function resolveCall(string $source, PhpDocument $php, PhpMethodCall $call, array $localRepositoryClasses): ?array
    {
        return $this->resolve($source, $php, $call, $localRepositoryClasses, $this->repositoryAssignmentEntities($source, $php));
    }

    /**
     * @param array<string, true>                                                                     $localRepositoryClasses
     * @param array<string, array{entityClass: string, variable: string, scopeStartOffset: int|null}> $assignments
     *
     * @return array{entityClass: ?string, repositoryClass: ?string}|null
     */
    private function resolve(string $source, PhpDocument $php, PhpMethodCall $call, array $localRepositoryClasses, array $assignments): ?array
    {
        $receiver = $call->receiverContext;
        if (PhpMethodReceiverKind::This === $receiver->kind) {
            foreach ($php->typeDeclarations as $type) {
                if ($type->contains($call->startOffset) && isset($localRepositoryClasses[$type->name])) {
                    return ['entityClass' => null, 'repositoryClass' => $type->name];
                }
            }
        }
        if (null !== $receiver->name) {
            foreach ($this->capturedReceivers->variables($source, $php, $call) as $variable) {
                if (1 === \count($variable->types) && str_ends_with($variable->types[0], 'Repository')) {
                    return ['entityClass' => null, 'repositoryClass' => $variable->types[0]];
                }
            }
            $assignment = $assignments[$this->variableScopeKey($call, $receiver->name)] ?? null;
            if (null !== $assignment) {
                return ['entityClass' => $assignment['entityClass'], 'repositoryClass' => null];
            }
            $entities = [];
            foreach ($assignments as $assignment) {
                if ($receiver->name !== $assignment['variable']
                    || !\is_int($assignment['scopeStartOffset'])
                    || !$this->capturedReceivers->isCapturedFromScope($source, $call, $receiver->name, $assignment['scopeStartOffset'])
                ) {
                    continue;
                }
                $entities[$assignment['entityClass']] = true;
            }
            if (1 === \count($entities)) {
                return ['entityClass' => array_key_first($entities), 'repositoryClass' => null];
            }
        }
        if (PhpMethodReceiverKind::Other !== $receiver->kind) {
            return null;
        }
        $repositoryCall = array_find(
            $php->methodCalls,
            static fn (PhpMethodCall $candidate): bool => 'getRepository' === $candidate->method
                && $receiver->startOffset === $candidate->startOffset
                && $receiver->endOffset === $candidate->endOffset,
        );
        $reference = $repositoryCall?->positionalArgument(0)?->completeClassReference;

        return null !== $reference ? ['entityClass' => $reference->className, 'repositoryClass' => null] : null;
    }

    /** @return array<string, array{entityClass: string, variable: string, scopeStartOffset: int|null}> */
    private function repositoryAssignmentEntities(string $source, PhpDocument $php): array
    {
        $entities = [];
        foreach ($php->methodCalls as $call) {
            if ('getRepository' !== $call->method || null === $reference = $call->positionalArgument(0)?->completeClassReference) {
                continue;
            }
            $before = substr($source, 0, $call->startOffset);
            $boundary = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', substr($before, $boundary + 1), $assignment)) {
                $entities[$this->variableScopeKey($call, $assignment[1])] = [
                    'entityClass' => $reference->className,
                    'variable' => $assignment[1],
                    'scopeStartOffset' => $call->scopeStartOffset,
                ];
            }
        }

        return $entities;
    }

    private function variableScopeKey(PhpMethodCall $call, string $variable): string
    {
        return ($call->scopeStartOffset ?? -1).'|'.$variable;
    }
}
