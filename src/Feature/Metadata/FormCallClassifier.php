<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpReceiverMatch;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;

final class FormCallClassifier
{
    private const FORM_FACTORY_TYPES = [
        'Symfony\\Component\\Form\\FormFactoryInterface',
        'Symfony\\Component\\Form\\FormFactory',
    ];

    public function isFormCall(string $source, PhpDocument $php, PhpMethodCall $call): bool
    {
        if (!\in_array($call->method, ['createForm', 'createNamed', 'add'], true)) {
            return false;
        }

        return 'add' === $call->method
            ? null !== $this->builderVariable($source, $php, $call)
            : $this->createsFormThroughSymfony($php, $call);
    }

    /** @return list<PhpMethodCall> */
    public function formCalls(string $source, PhpDocument $php): array
    {
        return array_values(array_filter($php->methodCalls, fn (PhpMethodCall $call): bool => $this->isFormCall($source, $php, $call)));
    }

    public function typeArgument(PhpMethodCall $call): ?PhpArgument
    {
        return match ($call->method) {
            'createForm' => $call->positionalArgument(0),
            'createNamed', 'add' => $call->positionalArgument(1),
            default => null,
        };
    }

    public function optionsArgument(PhpMethodCall $call): ?PhpArgument
    {
        return match ($call->method) {
            'createForm', 'add' => $call->positionalArgument(2),
            'createNamed' => $call->positionalArgument(3),
            default => null,
        };
    }

    public function builderVariable(string $source, PhpDocument $php, PhpMethodCall $call): ?PhpTypedVariable
    {
        foreach ($this->builderReceiverVariables($php, $call) as $variable) {
            if (PhpTypedVariableKind::Parameter !== $variable->kind
                || !\in_array('Symfony\\Component\\Form\\FormBuilderInterface', $variable->types, true)
                || 1 !== preg_match('/^\s*\$'.preg_quote($variable->name, '/').'\b/', $call->receiver)
            ) {
                continue;
            }

            return $variable;
        }

        return null;
    }

    /** @return list<PhpTypedVariable> */
    public function builderReceiverVariables(PhpDocument $php, PhpMethodCall $call): array
    {
        do {
            if ([] !== $variables = $php->receiverVariables($call)) {
                return $variables;
            }
        } while (null !== $call = $php->receiverCall($call));

        return [];
    }

    /**
     * Whether a `createForm` or `createNamed` call is Symfony's: a controller
     * call on `$this`, or a call on a form factory.
     */
    private function createsFormThroughSymfony(PhpDocument $php, PhpMethodCall $call): bool
    {
        return match ($call->receiverContext->kind) {
            PhpMethodReceiverKind::This => true,
            PhpMethodReceiverKind::ThisProperty, PhpMethodReceiverKind::Variable => PhpReceiverMatch::Matches === $php->matchReceiver($call, ...self::FORM_FACTORY_TYPES),
            PhpMethodReceiverKind::Other => false,
        };
    }
}
