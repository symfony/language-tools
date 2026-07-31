<?php

function bridgeTwigSection(SymfonyLspBridgeContext $context): ?array
{
    $environment = $context->environment();
    $noDebug = !$context->debug();
    $paths = [];
    if (class_exists(Twig\Environment::class)) {
        try {
            $kernel = $context->kernel();
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $twig = runJsonCommand($application, [
                'command' => 'debug:twig',
                '--format' => 'json',
                '--env' => $environment,
                '--no-debug' => $noDebug,
                '--no-interaction' => true,
            ]);
            foreach (is_array($twig['loader_paths'] ?? null) ? $twig['loader_paths'] : [] as $namespace => $loaderPaths) {
                foreach (is_array($loaderPaths) ? $loaderPaths : [] as $path) {
                    if (is_string($namespace) && is_string($path)) {
                        $paths[] = ['namespace' => $namespace, 'path' => $path];
                    }
                }
            }
        } catch (Throwable $error) {
            $context->addError('twig', $error->getMessage());
        }
    }
    usort($paths, static fn (array $a, array $b): int => [$a['namespace'], $a['path']] <=> [$b['namespace'], $b['path']]);
    $section = [
        'complete' => true,
        'generation' => hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR)),
        'paths' => $paths,
        'resources' => [],
        'warnings' => [],
    ];

    return $section;
}
