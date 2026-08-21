<?php

function bridgeContainerSection(SymfonyLspBridgeContext $context): ?array
{
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('container');
    } else {
        try {
            $application = $context->application();
            $commandOptions = $context->commandOptions();
            $container = runJsonCommand($application, [
                'command' => 'debug:container',
                '--format' => 'json',
                '--show-hidden' => true,
                ...$commandOptions,
            ]);
            $types = runJsonCommand($application, [
                'command' => 'debug:container',
                '--types' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]);
            $parameterItems = normalizeParameters(runJsonCommand($application, [
                'command' => 'debug:container',
                '--parameters' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]));

            $items = normalizeServices($container, $types);
            $section = [
                'complete' => true,
                'servicesComplete' => false,
                'parametersComplete' => true,
                'items' => $items,
                'parameters' => $parameterItems,
                'resources' => [],
                'warnings' => [],
            ];
            $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $context->addError('container');
        }
    }

    return $section ?? null;
}
