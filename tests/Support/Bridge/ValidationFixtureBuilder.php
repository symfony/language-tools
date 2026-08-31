<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class ValidationFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeApplication(string $boot): void
    {
        $this->workspace->write('config/framework.yaml', "framework:\n    secret: CANARY_SECRET_YAML_VALUE\n");
        $source = $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\Config\Definition\Exception;
            class InvalidConfigurationException extends \RuntimeException
            {
                public function getPath(): ?string { return '.framework..cache.'; }
            }
            class InvalidTypeException extends InvalidConfigurationException {}
            class DuplicateKeyException extends InvalidConfigurationException {}
            class ForbiddenOverwriteException extends InvalidConfigurationException {}
            namespace Symfony\Component\Yaml\Exception;
            class ParseException extends \RuntimeException
            {
                public function __construct(string $message, private bool $withFile = true) { parent::__construct($message); }
                public function getParsedFile(): string { return $this->withFile ? \dirname(__DIR__).'/config/framework.yaml' : null; }
                public function getParsedLine(): int { return 7; }
                public function getSnippet(): string { return 'CANARY_SECRET_YAML_SNIPPET'; }
            }
            namespace Symfony\Component\Config\Util\Exception;
            class XmlParsingException extends \InvalidArgumentException {}
            namespace App;
            class ApplicationConfigurationException extends \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException {}
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void
                {
                    __BOOT__
                }
                public function shutdown(): void {}
            }
            PHP);
        $this->workspace->write('vendor/autoload.php', str_replace('__BOOT__', $boot, $source));
    }
}
