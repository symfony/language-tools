<?php

function symfonyLspBridgeContainerSection(SymfonyLspBridgeContext $context): array
{
    $items = [];
    $parameterItems = [];
    $parametersComplete = false;
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('container');
    } else {
        try {
            $application = $context->application();
            $commandOptions = $context->commandOptions();
            $container = symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:container',
                '--format' => 'json',
                '--show-hidden' => true,
                ...$commandOptions,
            ]);
            $types = symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:container',
                '--types' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]);
            $parameterItems = symfonyLspBridgeNormalizeParameters(symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:container',
                '--parameters' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]));
            $parametersComplete = true;

            $items = symfonyLspBridgeNormalizeServices($container, $types);
        } catch (Throwable $error) {
            $context->addError('container', $error);
        }
    }

    // debug:container hides the services the compiler inlines or removes
    return [
        'servicesComplete' => false,
        'parametersComplete' => $parametersComplete,
        'items' => $items,
        'parameters' => $parameterItems,
        'warnings' => [],
    ];
}
