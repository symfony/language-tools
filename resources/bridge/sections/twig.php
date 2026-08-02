<?php

function bridgeTwigSection(SymfonyLspBridgeContext $context): ?array
{
    $environment = $context->environment();
    $noDebug = !$context->debug();
    $paths = [];
    $globals = [];
    $warnings = [];
    $complete = true;
    if (class_exists(Twig\Environment::class)) {
        try {
            $kernel = $context->kernel();
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            if (!$application->has('debug:twig')) {
                $complete = false;
                $warnings[] = 'The debug:twig command is unavailable.';
            } else {
                $twig = runJsonCommand($application, [
                    'command' => 'debug:twig',
                    '--format' => 'json',
                    '--env' => $environment,
                    '--no-debug' => $noDebug,
                    '--no-interaction' => true,
                ]);
                foreach (is_array($twig['globals'] ?? null) ? array_keys($twig['globals']) : [] as $name) {
                    if (is_string($name)) {
                        $globals[] = $name;
                    }
                }
                foreach (is_array($twig['loader_paths'] ?? null) ? $twig['loader_paths'] : [] as $namespace => $loaderPaths) {
                    foreach (is_array($loaderPaths) ? $loaderPaths : [] as $path) {
                        if (is_string($namespace) && is_string($path)) {
                            $paths[] = ['namespace' => $namespace, 'path' => $path];
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $context->addError('twig', $error->getMessage());
        }
    }
    usort($paths, static fn (array $a, array $b): int => [$a['namespace'], $a['path']] <=> [$b['namespace'], $b['path']]);
    sort($globals);
    $section = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode([$paths, $globals], JSON_THROW_ON_ERROR)),
        'paths' => $paths,
        'globals' => $globals,
        'resources' => [],
        'warnings' => $warnings,
    ];

    return $section;
}
