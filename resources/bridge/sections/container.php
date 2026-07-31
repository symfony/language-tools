<?php

function bridgeContainerSection(SymfonyLspBridgeContext $context): ?array
{
    $environment = $context->environment();
    $noDebug = !$context->debug();
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('container', 'Symfony Console is unavailable.');
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
                'items' => $items,
                'parameters' => $parameterItems,
                'resources' => [],
                'warnings' => [],
            ];
            $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            $context->addError('container', $error->getMessage());
        }
    }

    return $section ?? null;
}
