<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class ConfigurationFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeConfigurationApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function join(string ...$paths): string { return implode('/', $paths); }
            }
            namespace Symfony\Component\DependencyInjection;
            final class ContainerBuilder
            {
                public function setParameter(string $name, mixed $value): void {}
                public function registerExtension(object $extension): void {}
            }
            namespace Symfony\Component\Config\Definition;
            abstract class TestNode
            {
                public function __construct(private string $name) {}
                public function getName(): string { return $this->name; }
                public function isRequired(): bool { return false; }
                public function hasDefaultValue(): bool { return false; }
                public function getDefaultValue(): mixed { return null; }
                public function getInfo(): ?string { return null; }
                public function getExample(): mixed { return null; }
                public function isDeprecated(): bool { return false; }
            }
            final class ScalarNode extends TestNode
            {
                public function hasDefaultValue(): bool { return true; }
                public function getDefaultValue(): mixed { return 'CANARY_SECRET_CONFIG_DEFAULT'; }
            }
            final class EnumNode extends TestNode
            {
                public function __construct(string $name, private bool $normalizeStrings) { parent::__construct($name); }
                public function getValues(): array { return \App\ResetMode::cases(); }
                public function normalize(mixed $value): mixed { return $this->normalizeStrings && is_string($value) ? \App\ResetMode::tryFrom($value) ?? $value : $value; }
                public function finalize(mixed $value): mixed
                {
                    if (!in_array($value, $this->getValues(), true)) { throw new \RuntimeException(); }

                    return $value;
                }
            }
            final class ArrayNode extends TestNode
            {
                public function __construct(string $name, private bool $normalizeKeys = true) { parent::__construct($name); }
                public function getChildren(): array
                {
                    return 'framework' === $this->getName()
                        ? [new ScalarNode('secret'), new self('csp', false), new EnumNode('reset_mode', true), new EnumNode('strict_reset_mode', false)]
                        : [new ScalarNode('default-src')];
                }
                public function getXmlRemappings(): array { return 'framework' === $this->getName() ? [['alias', 'secret']] : []; }
                public function getKeyAttribute(): ?string { return 'framework' === $this->getName() ? 'name' : null; }
            }
            namespace App;
            enum ResetMode: string
            {
                case SCHEMA = 'schema';
                case MIGRATE = 'migrate';
            }
            final class TreeBuilder
            {
                public function buildTree(): object { return new \Symfony\Component\Config\Definition\ArrayNode('framework'); }
            }
            final class Configuration
            {
                public function getConfigTreeBuilder(): object { return new TreeBuilder(); }
            }
            final class Extension
            {
                public function getAlias(): string { return 'framework'; }
                public function getConfiguration(array $config, object $container): object { return new Configuration(); }
            }
            final class Bundle
            {
                public function getContainerExtension(): object { return new Extension(); }
            }
            final class BrokenExtension
            {
                public function getAlias(): string { return 'broken'; }
                public function getConfiguration(array $config, object $container): object { throw new \RuntimeException('CANARY_CONFIGURATION_EXCEPTION'); }
            }
            final class BrokenBundle
            {
                public function getContainerExtension(): object { return new BrokenExtension(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void {}
                public function shutdown(): void {}
                public function getBundles(): array { return [new Bundle()]; }
            }
            PHP,
        ));
    }
}
