<?php

namespace Symfony\Lsp\Server;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Filesystem\Path;

final class ContainerFactory
{
    public function create(string $serverVersion): ContainerBuilder
    {
        $resources = Path::join(\dirname(__DIR__, 2), 'resources');
        $container = new ContainerBuilder();
        $container->setParameter('server.version', $serverVersion);
        $container->setParameter('bridge.source', Path::join($resources, 'bridge.php'));
        (new PhpFileLoader($container, new FileLocator($resources)))->load('services.php');

        return $container;
    }
}
