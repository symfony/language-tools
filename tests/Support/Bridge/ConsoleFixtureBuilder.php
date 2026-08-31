<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class ConsoleFixtureBuilder
{
    public function __construct(private readonly BridgeFixtureWorkspace $workspace)
    {
    }

    public function writeDefinitionsScript(): string
    {
        return $this->workspace->writeExecutable('console.php', str_replace(
            '__BRIDGE__',
            var_export(\dirname(__DIR__, 3).'/resources/bridge/sections/console.php', true),
            <<<'PHP'
                <?php
                require __BRIDGE__;

                final class Definition
                {
                    public function __construct(private array $arguments, private array $options) {}
                    public function getArguments(): array { return array_fill_keys($this->arguments, new stdClass()); }
                    public function getOptions(): array { return array_fill_keys($this->options, new stdClass()); }
                }

                final class InvokableService
                {
                    public function __invoke(): void {}
                }

                final class WrappedCommand
                {
                    public function __construct(private Definition $definition, private InvokableService $service) {}
                    public function getDefinition(): Definition { return $this->definition; }
                    public function getCode(): InvokableService { return $this->service; }
                }

                final class Application
                {
                    public function getDefinition(): Definition { return new Definition(['command'], ['help', 'verbose', 'env', 'no-debug']); }
                    public function all(): array
                    {
                        return [
                            'first' => new WrappedCommand(new Definition(['report'], ['format']), new InvokableService()),
                            'second' => new WrappedCommand(new Definition(['report'], ['color']), new InvokableService()),
                        ];
                    }
                }

                echo json_encode(symfonyLspBridgeConsoleDefinitions(new Application(), __DIR__), JSON_THROW_ON_ERROR);
                PHP,
        ));
    }
}
