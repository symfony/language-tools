<?php

function bridgeMetadataSection(SymfonyLspBridgeContext $context): ?array
{
    $forms = [];
    $constraints = [];
    $warnings = [];
    $formsComplete = false;
    $constraintsComplete = false;

    if (interface_exists(Symfony\Component\Form\FormTypeInterface::class)) {
        try {
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($context->kernel());
            $application->setAutoExit(false);
            if (!$application->has('debug:form')) {
                $warnings[] = 'The debug:form command is unavailable.';
            } else {
                $commandOptions = [
                    '--format' => 'json',
                    '--env' => $context->environment(),
                    '--no-debug' => !$context->debug(),
                    '--no-interaction' => true,
                ];
                $formList = runJsonCommand($application, ['command' => 'debug:form', ...$commandOptions]);
                $types = [];
                foreach (['builtin_form_types', 'service_form_types'] as $key) {
                    foreach (is_array($formList[$key] ?? null) ? $formList[$key] : [] as $type) {
                        if (is_string($type)) {
                            $types[$type] = true;
                        }
                    }
                }
                foreach (array_keys($types) as $type) {
                    try {
                        $metadata = runJsonCommand($application, ['command' => 'debug:form', 'class' => $type, ...$commandOptions]);
                        $options = [];
                        foreach (bridgeMetadataStringLeaves(is_array($metadata['options'] ?? null) ? $metadata['options'] : []) as $name) {
                            $options[$name] = true;
                        }
                        $required = array_values(array_filter(is_array($metadata['options']['required'] ?? null) ? $metadata['options']['required'] : [], 'is_string'));
                        $optionNames = array_keys($options);
                        sort($optionNames);
                        sort($required);
                        $forms[$type] = [
                            'class' => $type,
                            'blockPrefix' => is_string($metadata['block_prefix'] ?? null) ? $metadata['block_prefix'] : null,
                            'options' => $optionNames,
                            'requiredOptions' => $required,
                        ];
                    } catch (Throwable $error) {
                        $warnings[] = sprintf('Form %s: %s', $type, $error->getMessage());
                    }
                }
                $formsComplete = count($forms) === count($types);
            }
        } catch (Throwable $error) {
            $context->addError('metadata', $error->getMessage());
        }
    }

    if (class_exists(Symfony\Component\Validator\Constraint::class)) {
        try {
            $directory = rtrim($context->project(), '/\\').'/vendor/symfony/validator/Constraints';
            foreach (glob($directory.'/*.php') ?: [] as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                $class = 'Symfony\\Component\\Validator\\Constraints\\'.$name;
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(Symfony\Component\Validator\Constraint::class)) {
                    continue;
                }
                $options = [];
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                    $options[] = $parameter->getName();
                }
                sort($options);
                $constraints[$name] = ['name' => $name, 'class' => $class, 'options' => $options];
            }
            $constraintsComplete = is_dir($directory) && [] !== $constraints;
        } catch (Throwable $error) {
            $context->addError('metadata', $error->getMessage());
        }
    }

    ksort($forms);
    ksort($constraints);
    sort($warnings);
    $section = [
        'formsComplete' => $formsComplete,
        'constraintsComplete' => $constraintsComplete,
        'forms' => array_values($forms),
        'constraints' => array_values($constraints),
        'resources' => [],
        'warnings' => $warnings,
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}

function bridgeMetadataStringLeaves(array $values): array
{
    $strings = [];
    foreach ($values as $value) {
        if (is_string($value)) {
            $strings[] = $value;
        } elseif (is_array($value)) {
            array_push($strings, ...bridgeMetadataStringLeaves($value));
        }
    }

    return $strings;
}
