<?php

function symfonyLspBridgeContainerSection(SymfonyLspBridgeContext $context): ?array
{
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

            $items = symfonyLspBridgeNormalizeServices($container, $types);
            $section = [
                'complete' => true,
                'servicesComplete' => false,
                'parametersComplete' => true,
                'items' => $items,
                'parameters' => $parameterItems,
                'resources' => [],
                'warnings' => [],
            ];
            $section = symfonyLspBridgeFinalizeSection($section);
        } catch (Throwable $error) {
            $context->addError('container', $error);
        }
    }

    return $section ?? null;
}
