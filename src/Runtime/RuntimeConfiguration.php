<?php

namespace Symfony\Lsp\Runtime;

final class RuntimeConfiguration
{
    /** @var non-empty-list<string> */
    private array $phpCommand = ['php'];
    private string $environment = 'dev';
    private bool $debug = true;

    /**
     * @param array<array-key, mixed> $initializationOptions
     */
    public function configure(array $initializationOptions): void
    {
        $phpCommand = $initializationOptions['phpCommand'] ?? null;
        if (\is_array($phpCommand) && [] !== $phpCommand) {
            $validatedCommand = [];
            foreach ($phpCommand as $argument) {
                if (!\is_string($argument) || '' === $argument) {
                    $validatedCommand = [];
                    break;
                }

                $validatedCommand[] = $argument;
            }

            if ([] !== $validatedCommand) {
                $this->phpCommand = $validatedCommand;
            }
        }

        $environment = $initializationOptions['environment'] ?? null;
        if (\is_string($environment) && '' !== $environment) {
            $this->environment = $environment;
        }

        $debug = $initializationOptions['debug'] ?? null;
        if (\is_bool($debug)) {
            $this->debug = $debug;
        }
    }

    /**
     * @return non-empty-list<string>
     */
    public function phpCommand(): array
    {
        return $this->phpCommand;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function debug(): bool
    {
        return $this->debug;
    }
}
