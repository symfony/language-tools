<?php

function bridgeEnvironmentSection(SymfonyLspBridgeContext $context): ?array
{
    $processors = [];
    $complete = true;
    if (interface_exists(Symfony\Component\DependencyInjection\EnvVarProcessorInterface::class)) {
        $classes = class_exists(Symfony\Component\DependencyInjection\EnvVarProcessor::class)
            ? [Symfony\Component\DependencyInjection\EnvVarProcessor::class]
            : [];
        try {
            $application = $context->application();
            $tagged = runJsonCommand($application, [
                'command' => 'debug:container',
                '--tag' => 'container.env_var_processor',
                '--format' => 'json',
                ...$context->commandOptions(),
            ]);
            foreach (is_array($tagged['definitions'] ?? null) ? $tagged['definitions'] : [] as $definition) {
                if (is_array($definition) && is_string($definition['class'] ?? null)) {
                    $classes[] = $definition['class'];
                }
            }
        } catch (Throwable) {
            $complete = false;
            $context->addError('environment');
        }
        foreach (array_values(array_unique($classes)) as $class) {
            if (is_a($class, Symfony\Component\DependencyInjection\EnvVarProcessorInterface::class, true)) {
                foreach ($class::getProvidedTypes() as $name => $type) {
                    if (is_string($name) && is_string($type)) {
                        $processors[$name] = $type;
                    }
                }
            }
        }
    }
    ksort($processors);
    $processorItems = [];
    foreach ($processors as $name => $type) {
        $processorItems[] = ['name' => $name, 'type' => $type];
    }
    $section = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode($processorItems, JSON_THROW_ON_ERROR)),
        'processors' => $processorItems,
        'resources' => [],
        'warnings' => [],
    ];

    return $section;
}
