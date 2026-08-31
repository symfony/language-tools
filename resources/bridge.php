<?php

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "The Symfony Language Tools project bridge requires PHP 8.1 or newer.\n");
    exit(1);
}

$bridgeStartedAt = hrtime(true);
$elapsedMilliseconds = static fn (int $startedAt): float => round((hrtime(true) - $startedAt) / 1_000_000, 1);

// Stdout must stay payload-only; display_errors=stderr is not honored by every SAPI, so log errors to stderr instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '');
ob_start();
register_shutdown_function(static function (): void {
    $stray = '';
    while (ob_get_level() > 0) {
        $buffer = ob_get_clean();
        if (!is_string($buffer)) {
            break;
        }
        $stray = $buffer.$stray;
    }
    if ('' !== $stray) {
        fwrite(STDERR, $stray);
    }
});

require __DIR__.'/bridge/context.php';
require __DIR__.'/bridge/support.php';
require __DIR__.'/bridge/container.php';
require __DIR__.'/bridge/configuration.php';
require __DIR__.'/bridge/sections/routes.php';
require __DIR__.'/bridge/sections/container.php';
require __DIR__.'/bridge/sections/twig.php';
require __DIR__.'/bridge/sections/twig_components.php';
require __DIR__.'/bridge/sections/translations.php';
require __DIR__.'/bridge/sections/messenger.php';
require __DIR__.'/bridge/sections/events.php';
require __DIR__.'/bridge/sections/security.php';
require __DIR__.'/bridge/sections/metadata.php';
require __DIR__.'/bridge/sections/assets.php';
require __DIR__.'/bridge/sections/stimulus.php';
require __DIR__.'/bridge/sections/configuration.php';
require __DIR__.'/bridge/sections/doctrine.php';
require __DIR__.'/bridge/sections/environment.php';
require __DIR__.'/bridge/sections/console.php';

$options = getopt('', ['project:', 'environment::', 'debug::', 'sections::', 'targeted-refresh::', 'rebuild-container::', 'configuration-generation::', 'release-metadata-url:', 'release-metadata-cache:']);
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

if (!preg_match('/^(?:v)?([0-9]+\.[0-9]+)(?:\.|$)/', $version, $matches)) {
    fwrite(STDERR, sprintf("Symfony FrameworkBundle %s does not identify a release branch.\n", $version));
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
$targetedRefreshOption = $options['targeted-refresh'] ?? '0';
$targetedRefresh = !in_array($targetedRefreshOption, ['0', 'false'], true);
$rebuildContainerOption = $options['rebuild-container'] ?? '0';
$rebuildContainer = !in_array($rebuildContainerOption, ['0', 'false'], true);
$configurationGenerationOption = $options['configuration-generation'] ?? '0';
$configurationGeneration = is_string($configurationGenerationOption) && ctype_digit($configurationGenerationOption)
    ? (int) $configurationGenerationOption
    : 0;
$projectMetadata = [
    'root' => realpath($project) ?: $project,
    'symfonyVersion' => $version,
    'symfonyBranch' => $matches[1],
    'phpVersion' => PHP_VERSION,
    'environment' => $environment,
    'debug' => $debug,
];
$releaseMetadataUrl = $options['release-metadata-url'] ?? null;
$releaseMetadataCache = $options['release-metadata-cache'] ?? null;
if (is_string($releaseMetadataUrl) && '' !== $releaseMetadataUrl
    && is_string($releaseMetadataCache) && '' !== $releaseMetadataCache
) {
    $supportedVersions = symfonyLspBridgeSupportedVersions($releaseMetadataUrl, $releaseMetadataCache);
    if (is_array($supportedVersions) && !symfonyLspBridgeSupportsBranch($matches[1], $supportedVersions)) {
        fwrite(STDOUT, json_encode([
            'schemaVersion' => 1,
            'project' => $projectMetadata,
            'unsupportedSymfonyVersion' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        exit(0);
    }
}

$projectRoot = rtrim($project, '/\\');
$hasEnvFile = is_file($projectRoot.'/.env') || is_file($projectRoot.'/.env.dist') || is_file($projectRoot.'/.env.local.php');
if (class_exists(Symfony\Component\Runtime\SymfonyRuntime::class)) {
    new Symfony\Component\Runtime\SymfonyRuntime([
        'project_dir' => $project,
        'env' => $environment,
        'debug' => $debug,
        // applications such as Shopware ship no env file at all
        'disable_dotenv' => !$hasEnvFile,
    ]);
} elseif ($hasEnvFile && class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(
        $projectRoot.'/.env',
        $environment,
    );
}

$context = new SymfonyLspBridgeContext($project, $environment, $debug, $targetedRefresh, $rebuildContainer);
$bootstrapMilliseconds = $elapsedMilliseconds($bridgeStartedAt);
$kernelStartedAt = hrtime(true);
$configurationValidation = $context->configurationValidation();
$kernelMilliseconds = $elapsedMilliseconds($kernelStartedAt);
$sections = [];
$sectionMilliseconds = [];
foreach ($requestedSections as $sectionName) {
    $sectionStartedAt = hrtime(true);
    try {
        $section = match ($sectionName) {
            'routes' => symfonyLspBridgeRoutesSection($context),
            'container' => symfonyLspBridgeContainerSection($context),
            'twig' => symfonyLspBridgeTwigSection($context),
            'twig_components' => symfonyLspBridgeTwigComponentsSection($context),
            'translations' => symfonyLspBridgeTranslationsSection($context),
            'messenger' => symfonyLspBridgeMessengerSection($context),
            'events' => symfonyLspBridgeEventsSection($context),
            'security' => symfonyLspBridgeSecuritySection($context),
            'metadata' => symfonyLspBridgeMetadataSection($context),
            'assets' => symfonyLspBridgeAssetsSection($context),
            'stimulus' => symfonyLspBridgeStimulusSection($context),
            'configuration' => symfonyLspBridgeConfigurationSection($context),
            'doctrine' => symfonyLspBridgeDoctrineSection($context),
            'environment' => symfonyLspBridgeEnvironmentSection($context),
            'console' => symfonyLspBridgeConsoleSection($context),
            default => null,
        };
        if (is_array($section)) {
            $sections[$sectionName] = $section;
        }
    } catch (Throwable) {
        $context->addError($sectionName);
    } finally {
        $sectionMilliseconds[$sectionName] = $elapsedMilliseconds($sectionStartedAt);
    }
}

$shutdownStartedAt = hrtime(true);
try {
    $context->shutdown();
} catch (Throwable) {
}
$shutdownMilliseconds = $elapsedMilliseconds($shutdownStartedAt);
$totalMilliseconds = $elapsedMilliseconds($bridgeStartedAt);

$result = [
    'schemaVersion' => 1,
    'generation' => hash('sha256', json_encode([$configurationGeneration, $configurationValidation, $sections], JSON_THROW_ON_ERROR)),
    'project' => $projectMetadata,
    'configurationValidation' => $configurationValidation,
    'configurationGeneration' => $configurationGeneration,
    'sections' => $sections,
    'errors' => $context->errors(),
    'timings' => [
        'bootstrapMilliseconds' => $bootstrapMilliseconds,
        'kernelMilliseconds' => $kernelMilliseconds,
        'sectionsMilliseconds' => $sectionMilliseconds,
        'shutdownMilliseconds' => $shutdownMilliseconds,
        'totalMilliseconds' => $totalMilliseconds,
    ],
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
