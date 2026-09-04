<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $counter = getenv('SYMFONY_LSP_TEST_CONFIGURATION_COMPILE_COUNTER');
        if (false !== $counter && '' !== $counter) {
            $container->addCompilerPass(new class($counter) implements CompilerPassInterface {
                public function __construct(private readonly string $path)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    if (false === file_put_contents($this->path, "compiled\n", \FILE_APPEND | \LOCK_EX)) {
                        throw new \RuntimeException('Unable to record the configuration compilation.');
                    }
                }
            });
        }
    }
}
