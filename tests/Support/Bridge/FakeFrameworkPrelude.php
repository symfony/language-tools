<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class FakeFrameworkPrelude
{
    public function render(
        string $source,
        string $version = '8.0.6',
        string $additionalInstalledVersionMethods = '',
        ?string $applicationMembers = null,
        string $applicationConstructor = 'public function __construct(object $kernel) {}',
    ): string {
        return str_replace(
            ['__INSTALLED_VERSIONS__', '__CONSOLE_IO__', '__FRAMEWORK_APPLICATION__'],
            [
                $this->installedVersions($version, $additionalInstalledVersionMethods),
                $this->consoleIo(),
                null === $applicationMembers ? '' : $this->frameworkConsoleApplication($applicationMembers, $applicationConstructor),
            ],
            $source,
        );
    }

    public function installedVersions(string $version = '8.0.6', string $additionalMethods = '', bool $bracketedNamespace = false): string
    {
        return \sprintf(<<<'PHP'
            <?php
            %s
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return %s; }
                %s
            }
            %s
            PHP,
            $bracketedNamespace ? 'namespace Composer {' : 'namespace Composer;',
            var_export($version, true),
            $additionalMethods,
            $bracketedNamespace ? '}' : '',
        );
    }

    public function console(string $version = '8.0.6', string $additionalInstalledVersionMethods = ''): string
    {
        return $this->installedVersions($version, $additionalInstalledVersionMethods).$this->consoleIo();
    }

    public function consoleIo(): string
    {
        return <<<'PHP'

            namespace Symfony\Component\Console\Input;
            final class ArrayInput
            {
                public function __construct(public array $arguments) {}
            }
            namespace Symfony\Component\Console\Output;
            final class BufferedOutput
            {
                private string $contents = '';
                public function write(string $contents): void { $this->contents .= $contents; }
                public function fetch(): string { return $this->contents; }
            }
            PHP;
    }

    public function frameworkConsoleApplication(string $members, string $constructor = 'public function __construct(object $kernel) {}'): string
    {
        return <<<'PHP'

            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
            PHP."\n    ".$constructor."\n    public function setAutoExit(bool \$autoExit): void {}".$members."\n}\n";
    }
}
