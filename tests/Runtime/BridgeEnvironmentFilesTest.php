<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class BridgeEnvironmentFilesTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $fixture = realpath(__DIR__.'/../Fixtures/RuntimeApplication');
        if (false === $fixture || !is_file($fixture.'/vendor/autoload.php')) {
            self::markTestSkipped('The runtime application fixture dependencies are not installed.');
        }

        $this->workspace = new TestWorkspace();
        $this->workspace->write('vendor/autoload.php', \sprintf(<<<'PHP'
            <?php
            $loader = require %s;
            $loader->addPsr4('Nested\\', dirname(__DIR__).'/tests/Application/src/');

            return $loader;
            PHP, var_export($fixture.'/vendor/autoload.php', true)));
        $this->workspace->write('tests/Application/composer.json', '{}');
        $this->workspace->write('tests/Application/src/Kernel.php', <<<'PHP'
            <?php

            namespace Nested;

            use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
            use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
            use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
            use Symfony\Component\HttpKernel\Kernel as BaseKernel;

            final class Kernel extends BaseKernel
            {
                use MicroKernelTrait;

                public function registerBundles(): iterable
                {
                    yield new FrameworkBundle();
                }

                public function boot(): void
                {
                    if ('application' !== ($_SERVER['SYMFONY_LSP_NESTED_ENV'] ?? null)) {
                        throw new \RuntimeException('The application env file was not loaded.');
                    }

                    parent::boot();
                }

                protected function configureContainer(ContainerConfigurator $container): void
                {
                    $container->extension('framework', ['secret' => '%env(APP_SECRET)%']);
                }
            }
            PHP);
        $this->workspace->write('tests/Application/.env', "APP_SECRET=nested\nSYMFONY_LSP_NESTED_ENV=application\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testLoadsTheEnvFilesOfAKernelWhoseProjectDirectoryIsNotTheAnalyzedRoot(): void
    {
        $process = (new BridgeProcessFixture($this->workspace->rootPath))->run(['--kernel=Nested\\Kernel']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertSame(['status' => 'valid'], $process->snapshot['configurationValidation'] ?? null, $process->stdout);
    }

    public function testPrefersTheKernelProjectDirectoryOverTheAnalyzedRootEnvFiles(): void
    {
        $this->workspace->write('.env', "SYMFONY_LSP_NESTED_ENV=bundle\n");

        $process = (new BridgeProcessFixture($this->workspace->rootPath))->run(['--kernel=Nested\\Kernel']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertSame(['status' => 'valid'], $process->snapshot['configurationValidation'] ?? null, $process->stdout);
    }

    public function testLoadsTheEnvFilesOfTheRuntimeProjectDirectoryBeforeResolvingAnEntryPoint(): void
    {
        $this->workspace->write('composer.json', json_encode([
            'extra' => ['runtime' => ['class' => 'Nested\\Runtime', 'project_dir' => 'tests/Application']],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('vendor/autoload_runtime.php', <<<'PHP'
            <?php
            require_once __DIR__.'/autoload.php';
            PHP);
        $this->workspace->write('tests/Application/src/Runtime.php', <<<'PHP'
            <?php

            namespace Nested;

            final class Runtime
            {
                public function __construct(array $options = [])
                {
                }

                public function getResolver(\Closure $app): object
                {
                    return new class($app) {
                        public function __construct(private \Closure $app)
                        {
                        }

                        public function resolve(): array
                        {
                            return [$this->app, []];
                        }
                    };
                }
            }
            PHP);
        $this->workspace->write('tests/Application/bin/console', <<<'PHP'
            <?php
            require_once dirname(__DIR__, 3).'/vendor/autoload_runtime.php';

            return static function (): object {
                if ('application' !== ($_SERVER['SYMFONY_LSP_NESTED_ENV'] ?? null)) {
                    throw new \RuntimeException('The application env file was not loaded before the entry point.');
                }

                return new \Nested\Kernel('dev', true);
            };
            PHP);

        $process = (new BridgeProcessFixture($this->workspace->rootPath))->run(['--kernel=tests/Application/bin/console']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertSame(['status' => 'valid'], $process->snapshot['configurationValidation'] ?? null, $process->stdout);
    }
}
