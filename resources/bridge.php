<?php

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "Symfony LSP's project bridge requires PHP 8.1 or newer.\n");
    exit(1);
}

require __DIR__.'/bridge/context.php';
require __DIR__.'/bridge/support.php';
require __DIR__.'/bridge/container.php';
require __DIR__.'/bridge/configuration.php';
require __DIR__.'/bridge/sections/routes.php';
require __DIR__.'/bridge/sections/container.php';
require __DIR__.'/bridge/sections/twig.php';
require __DIR__.'/bridge/sections/translations.php';
require __DIR__.'/bridge/sections/messenger.php';
require __DIR__.'/bridge/sections/events.php';
require __DIR__.'/bridge/sections/security.php';
require __DIR__.'/bridge/sections/metadata.php';
require __DIR__.'/bridge/sections/configuration.php';
require __DIR__.'/bridge/sections/environment.php';

$options = getopt('', ['project:', 'environment::', 'debug::', 'sections::']);
$project = $options['project'] ?? null;
if (!is_string($project) || '' === $project) {
    fwrite(STDERR, "The --project option is required.\n");
    exit(1);
}

$autoload = rtrim($project, '/\\').'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "The project Composer autoloader was not found.\n");
    exit(1);
}

require $autoload;

if (!class_exists(Composer\InstalledVersions::class)) {
    fwrite(STDERR, "Composer runtime metadata is unavailable.\n");
    exit(1);
}

$version = Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle');
if (!is_string($version)) {
    fwrite(STDERR, "symfony/framework-bundle is not installed.\n");
    exit(1);
}

if (!preg_match('/^(?:v)?(6\.4|7\.4|8\.0|8\.1)(?:\.|$)/', $version, $matches)) {
    fwrite(STDERR, sprintf("Symfony FrameworkBundle %s is not supported.\n", $version));
    exit(1);
}

$environmentOption = $options['environment'] ?? 'dev';
$environment = is_string($environmentOption) ? $environmentOption : 'dev';
$debugOption = $options['debug'] ?? '1';
$debug = !in_array($debugOption, ['0', 'false'], true);
$requestedSections = $options['sections'] ?? '';
$requestedSections = is_string($requestedSections)
    ? array_values(array_filter(explode(',', $requestedSections)))
    : [];

if (class_exists(Symfony\Component\Runtime\SymfonyRuntime::class)) {
    new Symfony\Component\Runtime\SymfonyRuntime([
        'project_dir' => $project,
        'env' => $environment,
        'debug' => $debug,
    ]);
} elseif (class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(
        rtrim($project, '/\\').'/.env',
        $environment,
    );
}

$context = new SymfonyLspBridgeContext($project, $environment, $debug);
$sections = [];
foreach ($requestedSections as $sectionName) {
    try {
        $section = match ($sectionName) {
            'routes' => bridgeRoutesSection($context),
            'container' => bridgeContainerSection($context),
            'twig' => bridgeTwigSection($context),
            'translations' => bridgeTranslationsSection($context),
            'messenger' => bridgeMessengerSection($context),
            'events' => bridgeEventsSection($context),
            'security' => bridgeSecuritySection($context),
            'metadata' => bridgeMetadataSection($context),
            'configuration' => bridgeConfigurationSection($context),
            'environment' => bridgeEnvironmentSection($context),
            default => null,
        };
        if (is_array($section)) {
            $sections[$sectionName] = $section;
        }
    } catch (Throwable $error) {
        $context->addError($sectionName, $error->getMessage());
    }
}

try {
    $context->shutdown();
} catch (Throwable $error) {
    $context->addError('runtime', $error->getMessage());
}

$result = [
    'schemaVersion' => 1,
    'generation' => hash('sha256', json_encode($sections, JSON_THROW_ON_ERROR)),
    'project' => [
        'root' => realpath($project) ?: $project,
        'symfonyVersion' => $version,
        'symfonyBranch' => $matches[1],
        'phpVersion' => PHP_VERSION,
        'environment' => $environment,
        'debug' => $debug,
    ],
    'sections' => $sections,
    'errors' => $context->errors(),
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
