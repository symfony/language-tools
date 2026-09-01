<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParser
{
    private const FORMATS = ['human', 'json', 'github', 'gitlab', 'sarif'];

    public function __construct(
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly AnalysisSettings $analysisSettings,
        private readonly CheckArgumentsTokenizer $tokenizer,
    ) {
    }

    /** @param list<string> $arguments */
    public function parse(array $arguments): CheckOptionsParseResult
    {
        $tokenized = $this->tokenizer->tokenize($arguments);
        try {
            return new CheckOptionsParseResult($tokenized->format, $this->apply($tokenized));
        } catch (InvalidConfigurationException $error) {
            return new CheckOptionsParseResult($tokenized->format, $error);
        }
    }

    private function apply(TokenizedCheckArguments $arguments): CheckOptions
    {
        $workspace = getcwd();
        if (false === $workspace) {
            throw new InvalidConfigurationException('Unable to determine the current working directory.');
        }

        $draft = new CheckOptionsDraft($workspace);
        foreach ($arguments->tokens as $token) {
            if ('separator' === $token->kind) {
                continue;
            }
            if ('positional' === $token->kind) {
                $draft->selectors[] = $token->raw;

                continue;
            }
            if ('flag' === $token->kind) {
                $this->applyFlag($draft, $token->raw);

                continue;
            }

            $name = $token->name;
            $value = $token->value;
            if (null === $name || null === $value) {
                throw new \LogicException('A value option token must have a name and value.');
            }
            if ('' === $value && !\in_array($name, ['fail-on', 'format'], true)) {
                throw new InvalidConfigurationException(\sprintf('The check option "%s" requires a value.', $token->raw));
            }
            $this->applyValue($draft, $name, $value);
        }

        $draft->overrides = $this->analysisSettings->normalizeProject($draft->overrides, context: 'command-line');
        if ('none' !== $draft->baselineMode && null === $draft->baselinePath) {
            $draft->baselinePath = '.symfony-lsp-baseline.json';
        }
        if ($draft->strictBaseline && null === $draft->baselinePath) {
            throw new InvalidConfigurationException('The --strict-baseline option requires --baseline.');
        }

        return new CheckOptions(
            $draft->format,
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
            $draft->listCodes,
            $draft->help,
        );
    }

    private function applyFlag(CheckOptionsDraft $draft, string $option): void
    {
        match ($option) {
            '--help', '-h' => $draft->help = true,
            '--verbose' => $draft->verbose = true,
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
            default => throw new InvalidConfigurationException(\sprintf('The check option "%s" requires a value.', $option)),
        };
    }

    private function applyValue(CheckOptionsDraft $draft, string $name, string $value): void
    {
        switch ($name) {
            case 'format':
                $draft->selectedFormat = $this->format($draft->selectedFormat, $value);
                $draft->format = $draft->selectedFormat;
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

    private function format(?string $current, string $requested): string
    {
        if (!\in_array($requested, self::FORMATS, true)) {
            throw new InvalidConfigurationException('The --format option must be human, json, github, gitlab or sarif.');
        }
        if (null !== $current && $current !== $requested) {
            throw new InvalidConfigurationException('The --format option cannot select more than one format.');
        }

        return $requested;
    }

    private function baselineMode(string $current, string $requested): string
    {
        if ('none' !== $current && $current !== $requested) {
            throw new InvalidConfigurationException('The --generate-baseline and --refresh-baseline options cannot be combined.');
        }

        return $requested;
    }
}
