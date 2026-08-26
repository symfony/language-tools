<?php

function symfonyLspBridgeTwigComponentsSection(SymfonyLspBridgeContext $context): ?array
{
    if (!class_exists(Symfony\UX\TwigComponent\ComponentFactory::class)) {
        $section = [
            'complete' => true,
            'names' => [],
            'caseInsensitiveNames' => [],
            'anonymousTemplateDirectory' => 'components',
            'warnings' => [],
        ];
        $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

        return $section;
    }
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('twig_components');
    } else {
        try {
            $application = $context->application();
            $commandOptions = $context->commandOptions();
            $configuration = symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:config',
                'name' => 'twig_component',
                '--format' => 'json',
                ...$commandOptions,
            ]);
            $configuration = is_array($configuration['twig_component'] ?? null)
                ? $configuration['twig_component']
                : $configuration;
            $defaults = [];
            foreach (is_array($configuration['defaults'] ?? null) ? $configuration['defaults'] : [] as $namespace => $default) {
                if (!is_string($namespace)) {
                    continue;
                }
                $defaults[$namespace] = is_array($default) && is_string($default['name_prefix'] ?? null)
                    ? $default['name_prefix']
                    : '';
            }
            $anonymousTemplateDirectory = is_string($configuration['anonymous_template_directory'] ?? null)
                ? $configuration['anonymous_template_directory']
                : 'components';

            $definitionsByTag = [];
            foreach (['twig.component', 'ux.twig_component.twig_renderer'] as $tag) {
                $definitionsByTag[$tag] = [];
                foreach ([[], ['--show-hidden' => true]] as $visibilityOptions) {
                    $tagged = symfonyLspBridgeRunJsonCommand($application, [
                        'command' => 'debug:container',
                        '--tag' => $tag,
                        '--format' => 'json',
                        ...$visibilityOptions,
                        ...$commandOptions,
                    ]);
                    foreach (is_array($tagged['definitions'] ?? null) ? $tagged['definitions'] : [] as $id => $definition) {
                        if (is_string($id) && is_array($definition)) {
                            $definitionsByTag[$tag][$id] = $definition;
                        }
                    }
                }
            }
            $complete = true;
            $warnings = [];
            $names = [];
            $components = [];
            foreach ($definitionsByTag['twig.component'] as $definition) {
                $class = is_string($definition['class'] ?? null) ? $definition['class'] : null;
                foreach (symfonyLspBridgeDefinitionTagParameters($definition, 'twig.component') as $parameters) {
                    $name = is_string($parameters['key'] ?? null) && '' !== $parameters['key']
                        ? $parameters['key']
                        : (null === $class ? null : symfonyLspBridgeAutoTwigComponentName($class, $defaults));
                    if (null === $name) {
                        $complete = false;
                        $warnings[] = sprintf('Unable to derive the component name of "%s".', $class ?? 'an unnamed component service');
                        continue;
                    }
                    $names[$name] = true;
                    $file = null;
                    if (null !== $class && class_exists($class)) {
                        try {
                            $file = (new ReflectionClass($class))->getFileName() ?: null;
                        } catch (Throwable) {
                        }
                    }
                    $components[$name] = [
                        'name' => $name,
                        'class' => $class,
                        'file' => $file,
                        'template' => is_string($parameters['template'] ?? null) ? $parameters['template'] : null,
                        'live' => true === ($parameters['live'] ?? null),
                    ];
                }
            }
            $caseInsensitiveNames = [];
            foreach ($definitionsByTag['ux.twig_component.twig_renderer'] as $definition) {
                foreach (symfonyLspBridgeDefinitionTagParameters($definition, 'ux.twig_component.twig_renderer') as $parameters) {
                    $name = is_string($parameters['key'] ?? null) ? $parameters['key'] : null;
                    if (null !== $name && '' !== $name && strtolower($name) === $name) {
                        $caseInsensitiveNames[$name] = true;
                    }
                }
            }
            foreach (array_keys($names) as $name) {
                if (isset($caseInsensitiveNames[strtolower($name)])) {
                    unset($names[$name]);
                }
            }
            foreach ($caseInsensitiveNames as $name => $_) {
                $names[$name] = true;
            }
            $names = array_keys($names);
            sort($names);
            $caseInsensitiveNames = array_keys($caseInsensitiveNames);
            sort($caseInsensitiveNames);
            ksort($components);
            $components = array_values($components);
            $section = [
                'complete' => $complete,
                'generation' => hash('sha256', json_encode([$complete, $names, $caseInsensitiveNames, $anonymousTemplateDirectory, $components], JSON_THROW_ON_ERROR)),
                'names' => $names,
                'caseInsensitiveNames' => $caseInsensitiveNames,
                'anonymousTemplateDirectory' => $anonymousTemplateDirectory,
                'components' => $components,
                'warnings' => $warnings,
            ];
        } catch (Throwable) {
            $context->addError('twig_components');
        }
    }

    return $section ?? null;
}

// Mirrors the automatic naming rule of TwigComponentPass for tags without an explicit key.
function symfonyLspBridgeAutoTwigComponentName(string $class, array $defaults): ?string
{
    foreach ($defaults as $namespace => $namePrefix) {
        if (!str_starts_with($class, $namespace)) {
            continue;
        }
        $name = str_replace('\\', ':', substr($class, strlen($namespace)));

        return '' !== $namePrefix ? $namePrefix.':'.$name : $name;
    }

    return null;
}
