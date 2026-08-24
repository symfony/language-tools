<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParser
{
    public function __construct(
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly AnalysisSettings $analysisSettings,
    ) {
    }

    /** @param list<string> $arguments */
    public function parse(array $arguments): CheckOptions
    {
        $format = $this->selectedFormat($arguments);
        $workspace = getcwd();
        if (false === $workspace) {
            throw new InvalidConfigurationException('Unable to determine the current working directory.');
        }

        $configurationPath = null;
        $selectors = [];
        $projectRoots = [];
        $overrides = [];
        $blockingCodes = null;
        $baselinePath = null;
        $baselineMode = 'none';
        $strictBaseline = false;
        $timeout = 600.0;
        $verbose = false;
        $listCodes = false;
        $help = false;
        $positionals = false;

        foreach ($arguments as $argument) {
            if ($positionals) {
                $selectors[] = $argument;
                continue;
            }
            if ('--' === $argument) {
                $positionals = true;
                continue;
            }
            if (!str_starts_with($argument, '-')) {
                $selectors[] = $argument;
                continue;
            }
            if (\in_array($argument, ['--help', '-h'], true)) {
                $help = true;
                continue;
            }
            if ('--verbose' === $argument) {
                $verbose = true;
                continue;
            }
            if ('--list-codes' === $argument) {
                $listCodes = true;
                continue;
            }
            if ('--source-only' === $argument || '--no-runtime-indexing' === $argument) {
                $overrides['runtimeIndexing'] = false;
                continue;
            }
            if ('--runtime-indexing' === $argument) {
                $overrides['runtimeIndexing'] = true;
                continue;
            }
            if ('--debug' === $argument) {
                $overrides['debug'] = true;
                continue;
            }
            if ('--no-debug' === $argument) {
                $overrides['debug'] = false;
                continue;
            }
            if ('--no-container-project-root' === $argument) {
                $overrides['containerProjectRoot'] = null;
                continue;
            }
            if ('--translation-diagnostics' === $argument) {
                $overrides['translationDiagnostics'] = true;
                continue;
            }
            if ('--no-translation-diagnostics' === $argument) {
                $overrides['translationDiagnostics'] = false;
                continue;
            }
            if ('--generate-baseline' === $argument) {
                $baselineMode = $this->baselineMode($baselineMode, 'create');
                continue;
            }
            if ('--refresh-baseline' === $argument) {
                $baselineMode = $this->baselineMode($baselineMode, 'refresh');
                continue;
            }
            if ('--strict-baseline' === $argument) {
                $strictBaseline = true;
                continue;
            }

            [$name, $value] = $this->option($argument);
            match ($name) {
                'format' => null,
                'workspace' => $workspace = $value,
                'config' => $configurationPath = $value,
                'project-root' => $projectRoots[] = $value,
                'container-project-root' => $overrides['containerProjectRoot'] = $value,
                'environment' => $overrides['environment'] = $value,
                'bridge-timeout' => $overrides['bridgeTimeout'] = $this->positiveNumber($name, $value),
                'timeout' => $timeout = $this->positiveNumber($name, $value),
                'php-command' => $overrides['phpCommand'] = $this->phpCommand($value),
                'fail-on' => $blockingCodes = $this->blockingCodes($value),
                'baseline' => $baselinePath = $value,
                default => throw new InvalidConfigurationException(\sprintf('Unknown check option "--%s".', $name)),
            };
        }

        $overrides = $this->analysisSettings->normalizeProject($overrides, context: 'command-line');
        if ('none' !== $baselineMode && null === $baselinePath) {
            $baselinePath = '.symfony-lsp-baseline.json';
        }
        if ($strictBaseline && null === $baselinePath) {
            throw new InvalidConfigurationException('The --strict-baseline option requires --baseline.');
        }

        return new CheckOptions(
            $format,
            $workspace,
            $configurationPath,
            $selectors,
            array_values(array_unique($projectRoots)),
            $overrides,
            $blockingCodes,
            $baselinePath,
            $baselineMode,
            $strictBaseline,
            $timeout,
            $verbose,
            $listCodes,
            $help,
        );
    }

    /** @param list<string> $arguments */
    public function selectedFormat(array $arguments): string
    {
        $selected = null;
        foreach ($arguments as $argument) {
            if ('--' === $argument) {
                break;
            }
            if (!str_starts_with($argument, '--format=')) {
                continue;
            }

            $format = substr($argument, \strlen('--format='));
            if (!\in_array($format, ['human', 'json', 'github'], true)) {
                throw new InvalidConfigurationException('The --format option must be human, json or github.');
            }
            if (null !== $selected && $selected !== $format) {
                throw new InvalidConfigurationException('The --format option cannot select more than one format.');
            }
            $selected = $format;
        }

        return $selected ?? 'human';
    }

    /** @return array{string, string} */
    private function option(string $argument): array
    {
        if (1 !== preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)
            || ('' === $match[2] && 'fail-on' !== $match[1])
        ) {
            throw new InvalidConfigurationException(\sprintf('The check option "%s" requires a value.', $argument));
        }

        return [$match[1], $match[2]];
    }

    /** @return non-empty-list<string> */
    private function phpCommand(string $value): array
    {
        try {
            $command = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidConfigurationException('The --php-command option must be a JSON list of command arguments.');
        }
        $normalized = $this->analysisSettings->normalizeProject(['phpCommand' => $command], context: 'command-line');
        /** @var non-empty-list<string> $phpCommand */
        $phpCommand = $normalized['phpCommand'];

        return $phpCommand;
    }

    /** @return list<string> */
    private function blockingCodes(string $value): array
    {
        $codes = '' === $value ? [] : array_values(array_unique(explode(',', $value)));
        foreach ($codes as $code) {
            if (!$this->diagnosticCodes->contains($code)) {
                throw new InvalidConfigurationException(\sprintf('Unknown diagnostic code "%s". Run "symfony-lsp check --list-codes".', $code));
            }
        }
        sort($codes);

        return $codes;
    }

    private function positiveNumber(string $name, string $value): float
    {
        if (!is_numeric($value) || (float) $value <= 0 || !is_finite((float) $value)) {
            throw new InvalidConfigurationException(\sprintf('The --%s option must be a positive number.', $name));
        }

        return (float) $value;
    }

    private function baselineMode(string $current, string $requested): string
    {
        if ('none' !== $current && $current !== $requested) {
            throw new InvalidConfigurationException('The --generate-baseline and --refresh-baseline options cannot be combined.');
        }

        return $requested;
    }
}
