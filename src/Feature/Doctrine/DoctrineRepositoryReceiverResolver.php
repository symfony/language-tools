<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;

final class DoctrineRepositoryReceiverResolver
{
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
            $receiver = $this->resolve($php, $call, $localRepositoryClasses, $assignments);
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
        return $this->resolve($php, $call, $localRepositoryClasses, $this->repositoryAssignmentEntities($source, $php));
    }

    /**
     * @param array<string, true>   $localRepositoryClasses
     * @param array<string, string> $assignments
     *
     * @return array{entityClass: ?string, repositoryClass: ?string}|null
     */
    private function resolve(PhpDocument $php, PhpMethodCall $call, array $localRepositoryClasses, array $assignments): ?array
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
            foreach ($php->receiverVariables($call) as $variable) {
                if (1 === \count($variable->types) && str_ends_with($variable->types[0], 'Repository')) {
                    return ['entityClass' => null, 'repositoryClass' => $variable->types[0]];
                }
            }
            $entity = $assignments[$this->variableScopeKey($call, $receiver->name)] ?? null;
            if (null !== $entity) {
                return ['entityClass' => $entity, 'repositoryClass' => null];
            }
        }
        if (PhpMethodReceiverKind::Other !== $receiver->kind || 1 !== preg_match('/->\s*getRepository\s*\(/', $call->receiver)) {
            return null;
        }
        $references = [];
        foreach ($php->classReferences as $reference) {
            if ($reference->startOffset >= $receiver->startOffset && $reference->endOffset <= $receiver->endOffset) {
                $references[] = $reference;
            }
        }

        return 1 === \count($references) ? ['entityClass' => $references[0]->className, 'repositoryClass' => null] : null;
    }

    /** @return array<string, string> */
    private function repositoryAssignmentEntities(string $source, PhpDocument $php): array
    {
        $entities = [];
        foreach ($php->methodCalls as $call) {
            if ('getRepository' !== $call->method || null === $reference = $php->soleClassReference($call->positionalArgument(0))) {
                continue;
            }
            $before = substr($source, 0, $call->startOffset);
            $boundary = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', substr($before, $boundary + 1), $assignment)) {
                $entities[$this->variableScopeKey($call, $assignment[1])] = $reference->className;
            }
        }

        return $entities;
    }

    private function variableScopeKey(PhpMethodCall $call, string $variable): string
    {
        return ($call->scopeStartOffset ?? -1).'|'.$variable;
    }
}
