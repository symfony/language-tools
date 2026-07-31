<?php

function bridgeSecuritySection(SymfonyLspBridgeContext $context): ?array
{
    $firewalls = [];
    $providers = [];
    $roleHierarchy = [];
    $roles = [];
    $voters = [];
    $warnings = [];
    $complete = true;
    $securityEnabled = false;
    if (class_exists(Symfony\Bundle\SecurityBundle\SecurityBundle::class)) {
        try {
            foreach ($context->kernel()->getBundles() as $bundle) {
                if ($bundle instanceof Symfony\Bundle\SecurityBundle\SecurityBundle) {
                    $securityEnabled = true;
                    break;
                }
            }
        } catch (Throwable $error) {
            $complete = false;
            $context->addError('security', $error->getMessage());
        }
    }
    if ($securityEnabled) {
        try {
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($context->kernel());
            $application->setAutoExit(false);
            $commandOptions = [
                '--env' => $context->environment(),
                '--no-debug' => !$context->debug(),
                '--no-interaction' => true,
            ];
            $configuration = runJsonCommand($application, [
                'command' => 'debug:config',
                'name' => 'security',
                '--format' => 'json',
                ...$commandOptions,
            ]);
            $configuration = is_array($configuration['security'] ?? null) ? $configuration['security'] : $configuration;
            foreach (is_array($configuration['providers'] ?? null) ? $configuration['providers'] : [] as $name => $options) {
                if (!is_string($name) || !is_array($options)) {
                    continue;
                }
                $type = 'custom';
                foreach (['memory', 'entity', 'ldap', 'chain', 'id'] as $candidate) {
                    if (array_key_exists($candidate, $options)) {
                        $type = $candidate;
                        break;
                    }
                }
                $providers[$name] = ['name' => $name, 'type' => $type];
            }
            foreach (is_array($configuration['firewalls'] ?? null) ? $configuration['firewalls'] : [] as $name => $options) {
                if (!is_string($name) || !is_array($options)) {
                    continue;
                }
                $firewalls[$name] = [
                    'name' => $name,
                    'provider' => is_string($options['provider'] ?? null) ? $options['provider'] : null,
                    'enabled' => false !== ($options['security'] ?? true),
                    'stateless' => true === ($options['stateless'] ?? false),
                    'lazy' => true === ($options['lazy'] ?? false),
                    'authenticators' => array_values(array_filter(is_array($options['custom_authenticators'] ?? null) ? $options['custom_authenticators'] : [], 'is_string')),
                ];
            }
            foreach (is_array($configuration['role_hierarchy'] ?? null) ? $configuration['role_hierarchy'] : [] as $role => $inherited) {
                if (!is_string($role)) {
                    continue;
                }
                $inherited = is_string($inherited) ? [$inherited] : $inherited;
                $roleHierarchy[$role] = array_values(array_filter(is_array($inherited) ? $inherited : [], 'is_string'));
                $roles[$role] = true;
                foreach ($roleHierarchy[$role] as $inheritedRole) {
                    $roles[$inheritedRole] = true;
                }
            }
            foreach (is_array($configuration['access_control'] ?? null) ? $configuration['access_control'] : [] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $ruleRoles = is_string($rule['roles'] ?? null) ? [$rule['roles']] : ($rule['roles'] ?? []);
                foreach (is_array($ruleRoles) ? $ruleRoles : [] as $role) {
                    if (is_string($role)) {
                        $roles[$role] = true;
                    }
                }
            }
            try {
                $taggedVoters = runJsonCommand($application, [
                    'command' => 'debug:container',
                    '--tag' => 'security.voter',
                    '--format' => 'json',
                    ...$commandOptions,
                ]);
                foreach (is_array($taggedVoters['definitions'] ?? null) ? $taggedVoters['definitions'] : [] as $definition) {
                    if (is_array($definition) && is_string($definition['class'] ?? null)) {
                        $voters[$definition['class']] = ['class' => $definition['class']];
                    }
                }
            } catch (Throwable $error) {
                $warnings[] = 'Voters: '.$error->getMessage();
            }
        } catch (Throwable $error) {
            $complete = false;
            $context->addError('security', $error->getMessage());
        }
    }
    ksort($firewalls);
    ksort($providers);
    ksort($roles);
    ksort($voters);
    sort($warnings);
    $roleItems = [];
    foreach (array_keys($roles) as $role) {
        $roleItems[] = ['name' => $role, 'inheritedRoles' => $roleHierarchy[$role] ?? []];
    }
    $section = [
        'complete' => $complete,
        'firewalls' => array_values($firewalls),
        'providers' => array_values($providers),
        'roles' => $roleItems,
        'voters' => array_values($voters),
        'resources' => [],
        'warnings' => $warnings,
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}
