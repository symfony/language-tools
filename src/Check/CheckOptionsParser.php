<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParser
{
    private const DEFAULT_FORMAT = 'human';

    private const VALUE_OPTIONS = [
        '--workspace',
        '--config',
        '--project-root',
        '--container-project-root',
        '--environment',
        '--kernel',
        '--bridge-timeout',
        '--timeout',
        '--php-command',
        '--fail-on',
        '--baseline',
        '--format',
    ];

    /** @var list<string> */
    private readonly array $formats;

    /** @param iterable<CheckReportFormatInterface> $formats */
    public function __construct(
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly AnalysisSettings $analysisSettings,
        iterable $formats,
    ) {
        $names = [];
        foreach ($formats as $format) {
            $names[] = $format->name();
        }
        $this->formats = $names;
    }

    public function help(): string
    {
        return <<<HELP
            Usage: symfony-lsp check [options] [files, directories or patterns]

            Options:
              --format={$this->formatList('|')} Select the report format
              --workspace=PATH                 Set the workspace root
              --config=PATH                    Load a configuration file instead of .symfony-lsp.json
              --project-root=PATH              Select an explicit Symfony project root; repeatable
              --source-only                    Disable runtime indexing and application execution
              --php-command=JSON               Override the project PHP command argument list
              --container-project-root=PATH    Override the container-side project root
              --no-container-project-root      Run the project PHP command on the host
              --environment=NAME               Override the Symfony environment
              --kernel=CLASS|PATH              Select the kernel class or application entry point
              --debug, --no-debug              Enable or disable Symfony debug mode
              --runtime-indexing               Enable runtime indexing
              --no-runtime-indexing            Disable runtime indexing
              --bridge-timeout=SECONDS         Set each project bridge deadline
              --timeout=SECONDS                Set the complete check deadline; defaults to 600
              --verbose, -v, -vv, -vvv         Show sanitized operational failure causes
              --profile                        Report phase and diagnostic timing details
              --translation-diagnostics        Enable missing-translation diagnostics
              --no-translation-diagnostics     Disable missing-translation diagnostics
              --fail-on=CODE,...               Restrict blocking diagnostics to selected codes
              --list-codes                     List supported diagnostic codes
              --baseline=PATH                  Match an occurrence-specific baseline
              --generate-baseline              Create a new baseline
              --refresh-baseline               Replace an existing baseline
              --strict-baseline                Fail when baseline entries become stale
              --help, -h                       Display this help

            Runtime analysis executes application code. Use --source-only for untrusted code.
            HELP;
    }

    /**
     * Parsing never stops on the first failure so that the report format and
     * the verbosity are always resolved from the complete argument list.
     *
     * @param list<string> $arguments
     */
    public function parse(array $arguments): CheckOptions
    {
        $workspace = getcwd();
        $draft = new CheckOptionsDraft(false === $workspace ? '' : $workspace);
        if (false === $workspace) {
            $draft->error = new InvalidConfigurationException('Unable to determine the current working directory.');
        }

        $separated = false;
        foreach ($arguments as $argument) {
            if ($separated || !str_starts_with($argument, '-')) {
                $draft->selectors[] = $argument;

                continue;
            }
            if ('--' === $argument) {
                $separated = true;

                continue;
            }

            try {
                if (1 === preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)) {
                    $this->applyValue($draft, $match[1], $match[2], $argument);
                } else {
                    $this->applyFlag($draft, $argument);
                }
            } catch (InvalidConfigurationException $error) {
                $draft->error ??= $error;
            }
        }

        try {
            $draft->overrides = $this->analysisSettings->normalizeProject($draft->overrides, context: 'command-line');
        } catch (InvalidConfigurationException $error) {
            $draft->error ??= $error;
        }
        if ('none' !== $draft->baselineMode && null === $draft->baselinePath) {
            $draft->baselinePath = '.symfony-lsp-baseline.json';
        }
        if ($draft->strictBaseline && null === $draft->baselinePath) {
            $draft->error ??= new InvalidConfigurationException('The --strict-baseline option requires --baseline.');
        }

        $formats = array_keys($draft->formats);

        return new CheckOptions(
            1 === \count($formats) ? $formats[0] : self::DEFAULT_FORMAT,
            $draft->workspace,
            $draft->configurationPath,
            $draft->selectors,
            array_values(array_unique($draft->projectRoots)),
            $draft->overrides,
            $draft->blockingCodes,
            $draft->baselinePath,
            $draft->baselineMode,
            $draft->strictBaseline,
            $draft->timeout,
            $draft->verbose,
            $draft->profile,
            $draft->listCodes,
            $draft->help,
            $draft->error,
        );
    }

    private function applyFlag(CheckOptionsDraft $draft, string $option): void
    {
        match ($option) {
            '--help', '-h' => $draft->help = true,
            '--verbose', '-v', '-vv', '-vvv' => $draft->verbose = true,
            '--profile' => $draft->profile = true,
            '--list-codes' => $draft->listCodes = true,
            '--source-only', '--no-runtime-indexing' => $draft->overrides['runtimeIndexing'] = false,
            '--runtime-indexing' => $draft->overrides['runtimeIndexing'] = true,
            '--debug' => $draft->overrides['debug'] = true,
            '--no-debug' => $draft->overrides['debug'] = false,
            '--no-container-project-root' => $draft->overrides['containerProjectRoot'] = null,
            '--translation-diagnostics' => $draft->overrides['translationDiagnostics'] = true,
            '--no-translation-diagnostics' => $draft->overrides['translationDiagnostics'] = false,
            '--generate-baseline' => $draft->baselineMode = $this->baselineMode($draft->baselineMode, 'create'),
            '--refresh-baseline' => $draft->baselineMode = $this->baselineMode($draft->baselineMode, 'refresh'),
            '--strict-baseline' => $draft->strictBaseline = true,
            default => throw new InvalidConfigurationException($this->flagErrorMessage($option)),
        };
    }

    private function flagErrorMessage(string $option): string
    {
        if (\in_array($option, self::VALUE_OPTIONS, true)) {
            return \sprintf('The check option "%s" requires a value.', $option);
        }

        return \sprintf('Unknown check option "%s".', $option);
    }

    private function applyValue(CheckOptionsDraft $draft, string $name, string $value, string $argument): void
    {
        if ('' === $value && !\in_array($name, ['fail-on', 'format'], true)) {
            throw new InvalidConfigurationException(\sprintf('The check option "%s" requires a value.', $argument));
        }

        switch ($name) {
            case 'format':
                $this->selectFormat($draft, $value);
                break;
            case 'workspace':
                $draft->workspace = $value;
                break;
            case 'config':
                $draft->configurationPath = $value;
                break;
            case 'project-root':
                $draft->projectRoots[] = $value;
                break;
            case 'container-project-root':
                $draft->overrides['containerProjectRoot'] = $value;
                break;
            case 'environment':
                $draft->overrides['environment'] = $value;
                break;
            case 'kernel':
                $draft->overrides['kernel'] = $value;
                break;
            case 'bridge-timeout':
                $draft->overrides['bridgeTimeout'] = $this->positiveNumber($name, $value);
                break;
            case 'timeout':
                $draft->timeout = $this->positiveNumber($name, $value);
                break;
            case 'php-command':
                $draft->overrides['phpCommand'] = $this->phpCommand($value);
                break;
            case 'fail-on':
                $draft->blockingCodes = $this->blockingCodes($value);
                break;
            case 'baseline':
                $draft->baselinePath = $value;
                break;
            default:
                throw new InvalidConfigurationException(\sprintf('Unknown check option "--%s".', $name));
        }
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

    private function selectFormat(CheckOptionsDraft $draft, string $requested): void
    {
        if (!\in_array($requested, $this->formats, true)) {
            throw new InvalidConfigurationException(\sprintf('The --format option must be %s.', $this->formatList(', ', ' or ')));
        }

        $draft->formats[$requested] = true;
        if (\count($draft->formats) > 1) {
            throw new InvalidConfigurationException('The --format option cannot select more than one format.');
        }
    }

    private function formatList(string $separator, ?string $lastSeparator = null): string
    {
        $names = $this->formats;
        if (null === $lastSeparator || \count($names) < 2) {
            return implode($separator, $names);
        }
        $last = array_pop($names);

        return implode($separator, $names).$lastSeparator.$last;
    }

    private function baselineMode(string $current, string $requested): string
    {
        if ('none' !== $current && $current !== $requested) {
            throw new InvalidConfigurationException('The --generate-baseline and --refresh-baseline options cannot be combined.');
        }

        return $requested;
    }
}
