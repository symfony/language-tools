<?php

namespace Symfony\Lsp\Feature;

final class DiagnosticCodeRegistry
{
    // Configuration keys and values are deliberately checked in every environment
    private const CODES = [
        'config.deprecated_key' => DiagnosticCodeScope::EveryEnvironment,
        'config.duplicate_key' => DiagnosticCodeScope::EveryEnvironment,
        'config.invalid_type' => DiagnosticCodeScope::EveryEnvironment,
        'config.malformed_structure' => DiagnosticCodeScope::EveryEnvironment,
        'config.unknown_key' => DiagnosticCodeScope::EveryEnvironment,
        'console.unknown_argument' => DiagnosticCodeScope::SelectedEnvironment,
        'console.unknown_option' => DiagnosticCodeScope::SelectedEnvironment,
        'env.incompatible_type' => DiagnosticCodeScope::EveryEnvironment,
        'env.malformed_chain' => DiagnosticCodeScope::EveryEnvironment,
        'env.unknown_processor' => DiagnosticCodeScope::SelectedEnvironment,
        'event.invalid_listener_method' => DiagnosticCodeScope::EveryEnvironment,
        'form.unknown_option' => DiagnosticCodeScope::SelectedEnvironment,
        'importmap.unknown_entrypoint' => DiagnosticCodeScope::SelectedEnvironment,
        'messenger.invalid_handler_signature' => DiagnosticCodeScope::SelectedEnvironment,
        'messenger.unknown_bus' => DiagnosticCodeScope::SelectedEnvironment,
        'messenger.unknown_transport' => DiagnosticCodeScope::SelectedEnvironment,
        'parameter.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'route.missing_parameters' => DiagnosticCodeScope::SelectedEnvironment,
        'route.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'security.unknown_firewall' => DiagnosticCodeScope::SelectedEnvironment,
        'security.unknown_provider' => DiagnosticCodeScope::SelectedEnvironment,
        'service.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'stimulus.unknown_controller' => DiagnosticCodeScope::SelectedEnvironment,
        'suppression.invalid' => DiagnosticCodeScope::EveryEnvironment,
        'template.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'translation.domain_not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'translation.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'translation.placeholders' => DiagnosticCodeScope::EveryEnvironment,
        'twig_callable.unknown_argument' => DiagnosticCodeScope::SelectedEnvironment,
        'twig_component.not_found' => DiagnosticCodeScope::SelectedEnvironment,
        'validation.unknown_constraint_option' => DiagnosticCodeScope::SelectedEnvironment,
    ];

    /** @return list<string> */
    public function all(): array
    {
        return array_keys(self::CODES);
    }

    public function contains(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    public function scope(string $code): ?DiagnosticCodeScope
    {
        return self::CODES[$code] ?? null;
    }
}
