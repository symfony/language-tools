<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class FakeFrameworkPrelude
{
    public function installedVersions(string $version = '8.0.6', string $additionalMethods = ''): string
    {
        return \sprintf(<<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return %s; }
                %s
            }
            PHP,
            var_export($version, true),
            $additionalMethods,
        );
    }

    public function console(string $version = '8.0.6', string $additionalInstalledVersionMethods = ''): string
    {
        return $this->installedVersions($version, $additionalInstalledVersionMethods).<<<'PHP'

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

    public function frameworkConsoleApplication(string $members): string
    {
        return <<<'PHP'

            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
            PHP.$members."\n}\n";
    }
}
