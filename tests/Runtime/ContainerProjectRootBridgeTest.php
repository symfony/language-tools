<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Feature\Asset\AssetIndexRegistry;
use Symfony\Lsp\Feature\Asset\ProjectAssetSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\Stimulus\ProjectStimulusSnapshotLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Feature\Twig\ProjectTemplateSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\NativeProcessRunner;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeBridgeTimingNormalizer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Server\Utf8StringTruncator;

/**
 * Simulates a Docker bind mount without Docker: the host project root is a
 * symlink to the fixture, whose real path plays the container project root,
 * so bridge paths come back outside the host root and must be mapped.
 */
final class ContainerProjectRootBridgeTest extends TestCase
{
    private static function projects(Project ...$projects): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace(array_values($projects));

        return $registry;
    }

    public function testRunsTheBridgeThroughAMappedProjectRoot(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Symlink creation is not reliably available on Windows.');
        }
        $containerRoot = realpath(\dirname(__DIR__).'/Fixtures/RuntimeApplication');
        self::assertIsString($containerRoot);
        if (!is_file($containerRoot.'/vendor/autoload.php')) {
            self::markTestSkipped('The runtime fixture dependencies are not installed.');
        }

        $hostRoot = sys_get_temp_dir().'/symfony-lsp-host-'.bin2hex(random_bytes(8));
        symlink($containerRoot, $hostRoot);
        $project = new Project($hostRoot, 'file://'.$hostRoot);
        $configuration = new RuntimeConfiguration();
        $configuration->configure([
            'environment' => 'test',
            'containerProjectRoot' => $containerRoot,
        ]);
        $pathMapper = new ContainerPathMapper($configuration);
        $templateIndexes = new TemplateIndexRegistry(new DependencyInjectionSourceIndexRegistry());
        $assetIndexes = new AssetIndexRegistry();
        $stimulusIndexes = new StimulusIndexRegistry();
        $truncator = new Utf8StringTruncator();
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller(\dirname(__DIR__, 2).'/resources/bridge.php', 'container-test', new Filesystem()),
            new NativeProcessRunner(120.0),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectTemplateSnapshotLoader($templateIndexes, new UriToPathConverter(), $pathMapper),
                new ProjectAssetSnapshotLoader($assetIndexes, $pathMapper),
                new ProjectStimulusSnapshotLoader($stimulusIndexes, $pathMapper),
            ]),
            $configuration,
            $pathMapper,
            self::projects($project),
            new ProjectConfigurationValidationSnapshotLoader(new ConfigurationValidationRegistry()),
            new ProjectIndexStatusRegistry(),
            new RuntimeBridgeTimingNormalizer(),
            new ServerLogger(null, new SensitiveDataRedactor($truncator)),
            $truncator,
        );

        try {
            $initializer->initialize($project);

            $template = $templateIndexes->forProject($project)->get('fixture.html.twig');
            self::assertNotNull($template);
            self::assertStringStartsWith('file://'.$hostRoot.'/', $template->uri);
            $asset = $assetIndexes->forProject($project)->asset('app.js');
            self::assertNotNull($asset);
            self::assertStringStartsWith($hostRoot.'/', $asset->sourcePath);
            $controller = $stimulusIndexes->forProject($project)->controller('search');
            self::assertNotNull($controller);
            self::assertStringStartsWith($hostRoot.'/', $controller->sourcePath);
        } finally {
            (new Filesystem())->remove($containerRoot.'/var/symfony-lsp/container-test');
            unlink($hostRoot);
        }
    }
}
