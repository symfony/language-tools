<?php

function bridgeTwigComponentsSection(SymfonyLspBridgeContext $context): ?array
{
    if (!class_exists(Symfony\UX\TwigComponent\ComponentFactory::class)) {
        return null;
    }
    $environment = $context->environment();
    $noDebug = !$context->debug();
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('twig_components');
    } else {
        try {
            $kernel = $context->kernel();
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $commandOptions = [
                '--env' => $environment,
                '--no-debug' => $noDebug,
                '--no-interaction' => true,
            ];
            $configuration = runJsonCommand($application, [
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

            $definitions = [];
            foreach ([[], ['--show-hidden' => true]] as $visibilityOptions) {
                $tagged = runJsonCommand($application, [
                    'command' => 'debug:container',
                    '--tag' => 'twig.component',
                    '--format' => 'json',
                    ...$visibilityOptions,
                    ...$commandOptions,
                ]);
                foreach (is_array($tagged['definitions'] ?? null) ? $tagged['definitions'] : [] as $id => $definition) {
                    if (is_string($id) && is_array($definition)) {
                        $definitions[$id] = $definition;
                    }
                }
            }
            $complete = true;
            $warnings = [];
            $names = [];
            foreach ($definitions as $definition) {
                foreach (definitionTagParameters($definition, 'twig.component') as $parameters) {
                    if (is_string($parameters['key'] ?? null) && '' !== $parameters['key']) {
                        $names[$parameters['key']] = true;
                        continue;
                    }
                    $class = is_string($definition['class'] ?? null) ? $definition['class'] : null;
                    $name = null === $class ? null : autoTwigComponentName($class, $defaults);
                    if (null === $name) {
                        $complete = false;
                        $warnings[] = sprintf('Unable to derive the component name of "%s".', $class ?? 'an unnamed component service');
                        continue;
                    }
                    $names[$name] = true;
                }
            }
            $names = array_keys($names);
            sort($names);
            $section = [
                'complete' => $complete,
                'generation' => hash('sha256', json_encode([$complete, $names, $anonymousTemplateDirectory], JSON_THROW_ON_ERROR)),
                'names' => $names,
                'anonymousTemplateDirectory' => $anonymousTemplateDirectory,
                'warnings' => $warnings,
            ];
        } catch (Throwable) {
            $context->addError('twig_components');
        }
    }

    return $section ?? null;
}

// Mirrors the automatic naming rule of TwigComponentPass for tags without an explicit key.
function autoTwigComponentName(string $class, array $defaults): ?string
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
