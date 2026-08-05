<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ServiceConfigurationTest extends TestCase
{
    private const EXTENSION_POINTS = [
        CodeActionProviderInterface::class,
        CodeLensProviderInterface::class,
        CompletionProviderInterface::class,
        DefinitionProviderInterface::class,
        DiagnosticProviderInterface::class,
        DocumentLinkProviderInterface::class,
        HoverProviderInterface::class,
        ReferencesProviderInterface::class,
        RenameProviderInterface::class,
        SourceIndexProviderInterface::class,
        RuntimeSnapshotLoaderInterface::class,
    ];

    public function testRegistersEveryFeatureExtensionPoint(): void
    {
        $root = \dirname(__DIR__, 2);
        $container = new ContainerBuilder();
        $container->setParameter('server.version', 'test');
        $container->setParameter('bridge.source', $root.'/resources/bridge.php');
        (new PhpFileLoader($container, new FileLocator($root.'/resources')))->load('services.php');

        $files = (new Finder())->files()->name('*.php')->in($root.'/src/Feature');
        foreach ($files as $file) {
            $class = 'Symfony\\Lsp\\Feature\\'.str_replace(['/', '\\'], '\\', substr($file->getRelativePathname(), 0, -4));
            if (!class_exists($class)) {
                continue;
            }
            foreach (self::EXTENSION_POINTS as $interface) {
                if (is_subclass_of($class, $interface)) {
                    self::assertTrue($container->hasDefinition($class), \sprintf('The feature extension point "%s" is not registered.', $class));
                    break;
                }
            }
        }
    }
}
