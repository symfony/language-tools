<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class StimulusFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeThemedStimulusApplication(): void
    {
        $this->workspace->makeDirectory('src/ShopBundle/Resources/assets');
        $this->workspace->write('src/ShopBundle/Resources/assets/controllers.json', json_encode([
            'controllers' => ['@acme/ux-widget' => ['widget' => ['enabled' => true, 'fetch' => 'eager']]],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->makeDirectory('vendor/acme/ux-widget/assets/dist');
        $this->workspace->write('vendor/acme/ux-widget/assets/package.json', json_encode([
            'symfony' => ['controllers' => ['widget' => ['main' => 'dist/widget_controller.js', 'name' => 'acme/widget']]],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('vendor/acme/ux-widget/assets/dist/widget_controller.js', "export default class extends Controller {\n    refresh() {}\n}\n");
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\UX\StimulusBundle;
            final class StimulusBundle
            {
                public function getPath(): string { return __DIR__; }
            }
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function join(string ...$parts): string { return implode('/', $parts); }
                public static function canonicalize(string $path): string { return str_replace('\\', '/', $path); }
                public static function isBasePath(string $base, string $path): bool { return str_starts_with($path, rtrim($base, '/').'/'); }
            }
            __CONSOLE_IO__
            namespace App;
            final class ShopBundle
            {
                public function __construct(private string $path) {}
                public function getName(): string { return 'ShopBundle'; }
                public function getPath(): string { return $this->path; }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array
                {
                    return [new \Symfony\UX\StimulusBundle\StimulusBundle(), new ShopBundle(\dirname(__DIR__).'/src/ShopBundle')];
                }
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            additionalInstalledVersionMethods: <<<'PHP'
public static function isInstalled(string $package): bool { return 'acme/ux-widget' === $package; }
public static function getInstallPath(string $package): string { return \dirname(__DIR__).'/vendor/acme/ux-widget'; }
PHP,
            applicationMembers: <<<'PHP'
    public function has(string $name): bool { return true; }
    public function run(object $input, object $output): int
    {
        $project = \dirname(__DIR__);
        $output->write(json_encode(['stimulus' => [
            'controller_paths' => [$project.'/assets/controllers'],
            'controllers_json' => $project.'/assets/controllers.json',
        ]], JSON_THROW_ON_ERROR));

        return 0;
    }
PHP,
        ));
    }
}
