<?php

function symfonyLspBridgeMetadataSection(SymfonyLspBridgeContext $context): ?array
{
    $forms = [];
    $constraints = [];
    $warnings = [];
    $formsComplete = false;
    $constraintsComplete = false;

    if (interface_exists(Symfony\Component\Form\FormTypeInterface::class)) {
        try {
            $application = $context->application();
            if (!$application->has('debug:form')) {
                $warnings[] = 'The debug:form command is unavailable.';
            } else {
                $commandOptions = ['--format' => 'json', ...$context->commandOptions()];
                $formList = symfonyLspBridgeRunJsonCommand($application, ['command' => 'debug:form', ...$commandOptions]);
                $types = [];
                foreach (['builtin_form_types', 'service_form_types'] as $key) {
                    foreach (is_array($formList[$key] ?? null) ? $formList[$key] : [] as $type) {
                        if (is_string($type)) {
                            $types[$type] = true;
                        }
                    }
                }
                $descriptions = [];
                foreach (array_keys($types) as $type) {
                    $metadata = symfonyLspBridgeMetadataFormDescription($application, $commandOptions, $type, $descriptions);
                    if (null === $metadata) {
                        $warnings[] = sprintf('The %s form metadata is unavailable.', $type);
                        continue;
                    }
                    $options = symfonyLspBridgeMetadataFormOptions($application, $commandOptions, $metadata, $descriptions);
                    $required = array_values(array_filter(is_array($metadata['options']['required'] ?? null) ? $metadata['options']['required'] : [], 'is_string'));
                    sort($options);
                    sort($required);
                    $forms[$type] = [
                        'class' => $type,
                        'blockPrefix' => is_string($metadata['block_prefix'] ?? null) ? $metadata['block_prefix'] : null,
                        'options' => $options,
                        'requiredOptions' => $required,
                    ];
                }
                $formsComplete = count($forms) === count($types);
            }
        } catch (Throwable $error) {
            $context->addError('metadata', $error);
        }
    }

    if (class_exists(Symfony\Component\Validator\Constraint::class)) {
        try {
            $directory = rtrim($context->project(), '/\\').'/vendor/symfony/validator/Constraints';
            foreach (glob($directory.'/*.php') ?: [] as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                $class = 'Symfony\\Component\\Validator\\Constraints\\'.$name;
                try {
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
                } catch (Throwable) {
                    continue;
                }
            }
            $constraintsComplete = is_dir($directory) && [] !== $constraints;
        } catch (Throwable $error) {
            $context->addError('metadata', $error);
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
    return $section;
}

function symfonyLspBridgeMetadataFormDescription(object $application, array $commandOptions, string $type, array &$descriptions): ?array
{
    if (!array_key_exists($type, $descriptions)) {
        try {
            $descriptions[$type] = symfonyLspBridgeRunJsonCommand($application, ['command' => 'debug:form', 'class' => $type, ...$commandOptions]);
        } catch (Throwable) {
            $descriptions[$type] = null;
        }
    }

    return $descriptions[$type];
}

/*
 * debug:form attributes the options of a type extension to the highest type it
 * extends, so a type extension registered on both a type and one of its
 * ancestors describes no option at all below that ancestor. Options are
 * inherited along the parent chain, so their union restores them.
 */
function symfonyLspBridgeMetadataFormOptions(object $application, array $commandOptions, array $metadata, array &$descriptions): array
{
    $descriptionChain = [$metadata];
    foreach (is_array($metadata['parent_types'] ?? null) ? $metadata['parent_types'] : [] as $parent) {
        $parentMetadata = is_string($parent) ? symfonyLspBridgeMetadataFormDescription($application, $commandOptions, $parent, $descriptions) : null;
        if (is_array($parentMetadata)) {
            $descriptionChain[] = $parentMetadata;
        }
    }
    $options = [];
    foreach ($descriptionChain as $description) {
        foreach (symfonyLspBridgeMetadataStringLeaves(is_array($description['options'] ?? null) ? $description['options'] : []) as $name) {
            $options[$name] = true;
        }
    }

    return array_keys($options);
}

function symfonyLspBridgeMetadataStringLeaves(array $values): array
{
    $strings = [];
    foreach ($values as $value) {
        if (is_string($value)) {
            $strings[] = $value;
        } elseif (is_array($value)) {
            array_push($strings, ...symfonyLspBridgeMetadataStringLeaves($value));
        }
    }

    return $strings;
}
