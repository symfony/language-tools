<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Lsp\Server\ContainerFactory;

/**
 * The service container the server runs on, compiled with every service public
 * so that a test can reach the collaborator it exercises.
 *
 * Compiling it costs a quarter of a second, so it is dumped once per process
 * under a key covering everything its wiring is derived from, and instantiated
 * again for each test that needs services holding no state from another one.
 */
final class TestContainer
{
    /** @var class-string<Container>|null */
    private static ?string $class = null;

    public static function create(): Container
    {
        $class = self::$class ??= self::compile();

        return new $class();
    }

    /** Everything the compiled wiring is derived from, so that a dump of it can never be stale. */
    public static function key(string $root): string
    {
        $sources = ['services' => (string) md5_file($root.'/resources/services.php'), 'packages' => (string) md5_file($root.'/composer.lock')];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $sources[$file->getPathname()] = (string) md5_file($file->getPathname());
            }
        }
        ksort($sources);

        return md5(serialize($sources));
    }

    /** @return class-string<Container> */
    private static function compile(): string
    {
        $root = \dirname(__DIR__, 2);
        $class = 'TestContainer'.self::key($root);
        $directory = $root.'/var/cache/test-container';
        $file = $directory.'/'.$class.'.php';
        if (!is_file($file)) {
            self::dump($directory, $file, $class);
        }

        require_once $file;

        /** @var class-string<Container> $dumped */
        $dumped = __NAMESPACE__.'\\Container\\'.$class;

        return $dumped;
    }

    private static function dump(string $directory, string $file, string $class): void
    {
        $container = (new ContainerFactory())->create('test');
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
                foreach ($container->getAliases() as $alias) {
                    $alias->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The test container directory "%s" could not be created.', $directory));
        }
        foreach (glob($directory.'/TestContainer*.php') ?: [] as $stale) {
            unlink($stale);
        }
        $dumped = (new PhpDumper($container))->dump(['class' => $class, 'namespace' => __NAMESPACE__.'\\Container']);
        if (!\is_string($dumped)) {
            throw new \RuntimeException('The test container was dumped as several files.');
        }
        $temporary = $file.'.'.getmypid();
        file_put_contents($temporary, $dumped);
        rename($temporary, $file);
    }
}
