<?php

namespace App\Prepended;

use App\Prepended\DependencyInjection\FixturePrependedExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Mimics bundles such as storyblok/symfony-bundle that only declare their real
 * extension while prepending, replacing the empty one AbstractBundle generates.
 */
final class FixturePrependedBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerExtension(new FixturePrependedExtension());
    }
}
