<?php

namespace Symfony\Lsp\Check;

final class DiagnosticCodeRegistry
{
    private const CODES = [
        'config.deprecated_key',
        'config.duplicate_key',
        'config.invalid_type',
        'config.malformed_structure',
        'config.unknown_key',
        'env.incompatible_type',
        'env.malformed_chain',
        'env.unknown_processor',
        'event.invalid_listener_method',
        'form.unknown_option',
        'importmap.unknown_entrypoint',
        'messenger.invalid_handler_signature',
        'messenger.unknown_bus',
        'messenger.unknown_transport',
        'parameter.not_found',
        'route.missing_parameters',
        'route.not_found',
        'security.unknown_firewall',
        'security.unknown_provider',
        'service.not_found',
        'stimulus.unknown_controller',
        'template.not_found',
        'translation.domain_not_found',
        'translation.not_found',
        'translation.placeholders',
        'twig_callable.unknown_argument',
        'twig_component.not_found',
        'validation.unknown_constraint_option',
    ];

    /** @return list<string> */
    public function all(): array
    {
        return self::CODES;
    }

    public function contains(string $code): bool
    {
        return \in_array($code, self::CODES, true);
    }
}
