<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class AutoloaderFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeAutoloader(string $version): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->installedVersions($version));
    }

    public function writeApplicationGlobalFunctions(): void
    {
        $this->workspace->write('vendor/autoload.php', <<<'PHP'
            <?php
            namespace {
                function runJsonCommand(): string { return 'application'; }
                function serviceIds(): string { return 'application'; }
                function normalizeParameters(): string { return 'application'; }
                function configNodeType(): string { return 'application'; }
            }
            PHP.str_replace('<?php', '', $this->prelude->installedVersions('42.7.3', bracketedNamespace: true)));
    }

    public function writeStrayOutputApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->installedVersions('42.7.3').<<<'PHP'

            namespace App;
            echo "stray autoload output\n";
            trigger_error('Loading something deprecated.', \E_USER_DEPRECATED);
            PHP);
    }
}
