<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParser
{
    private const FORMATS = ['human', 'json', 'github', 'gitlab', 'sarif'];

    private const FLAG_OPTIONS = [
        '--help' => ['help', true],
        '-h' => ['help', true],
        '--verbose' => ['verbose', true],
        '--list-codes' => ['listCodes', true],
        '--source-only' => ['overrides.runtimeIndexing', false],
        '--no-runtime-indexing' => ['overrides.runtimeIndexing', false],
        '--runtime-indexing' => ['overrides.runtimeIndexing', true],
        '--debug' => ['overrides.debug', true],
        '--no-debug' => ['overrides.debug', false],
        '--no-container-project-root' => ['overrides.containerProjectRoot', null],
        '--translation-diagnostics' => ['overrides.translationDiagnostics', true],
        '--no-translation-diagnostics' => ['overrides.translationDiagnostics', false],
        '--generate-baseline' => ['baselineMode', 'create'],
        '--refresh-baseline' => ['baselineMode', 'refresh'],
        '--strict-baseline' => ['strictBaseline', true],
    ];

    private const VALUE_OPTIONS = [
        'format' => ['format', 'format'],
        'workspace' => ['workspace', 'string'],
        'config' => ['configurationPath', 'string'],
        'project-root' => ['projectRoots', 'append'],
        'container-project-root' => ['overrides.containerProjectRoot', 'string'],
        'environment' => ['overrides.environment', 'string'],
        'bridge-timeout' => ['overrides.bridgeTimeout', 'positive-number'],
        'timeout' => ['timeout', 'positive-number'],
        'php-command' => ['overrides.phpCommand', 'php-command'],
        'fail-on' => ['blockingCodes', 'blocking-codes'],
        'baseline' => ['baselinePath', 'string'],
    ];

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

        $values = [
            'format' => 'human',
            'workspace' => $workspace,
            'configurationPath' => null,
            'blockingCodes' => null,
            'baselinePath' => null,
            'strictBaseline' => false,
            'timeout' => 600.0,
            'verbose' => false,
            'listCodes' => false,
            'help' => false,
        ];
        $overrides = [];
        $selectors = [];
        $projectRoots = [];
        $baselineMode = 'none';
        $selectedFormat = null;

        foreach ($arguments->tokens as $token) {
            if ('separator' === $token->kind) {
                continue;
            }
            if ('positional' === $token->kind) {
                $selectors[] = $token->raw;
                continue;
            }
            if ('flag' === $token->kind) {
                $specification = self::FLAG_OPTIONS[$token->raw] ?? null;
                if (null === $specification) {
                    throw new InvalidConfigurationException(\sprintf('The check option "%s" requires a value.', $token->raw));
                }
                [$target, $value] = $specification;
                if ('baselineMode' === $target) {
                    if (!\in_array($value, ['create', 'refresh'], true)) {
                        throw new \LogicException('The baseline mode specification must request create or refresh.');
                    }
                    $baselineMode = $this->baselineMode($baselineMode, $value);
                } elseif (str_starts_with($target, 'overrides.')) {
                    $overrides[substr($target, \strlen('overrides.'))] = $value;
                } else {
                    $values[$target] = $value;
                }
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
            $specification = self::VALUE_OPTIONS[$name] ?? null;
            if (null === $specification) {
                throw new InvalidConfigurationException(\sprintf('Unknown check option "--%s".', $name));
            }
            [$target, $normalizer] = $specification;
            if ('format' === $normalizer) {
                $selectedFormat = $this->format($selectedFormat, $value);
                $normalized = $selectedFormat;
            } else {
                $normalized = match ($normalizer) {
                    'positive-number' => $this->positiveNumber($name, $value),
                    'php-command' => $this->phpCommand($value),
                    'blocking-codes' => $this->blockingCodes($value),
                    default => $value,
                };
            }
            if (str_starts_with($target, 'overrides.')) {
                $overrides[substr($target, \strlen('overrides.'))] = $normalized;
            } elseif ('append' === $normalizer) {
                if ('projectRoots' !== $target) {
                    throw new \LogicException('An appended option must target the project root list.');
                }
                $projectRoots[] = $normalized;
            } else {
                $values[$target] = $normalized;
            }
        }

        $overrides = $this->analysisSettings->normalizeProject($overrides, context: 'command-line');
        if ('none' !== $baselineMode && null === $values['baselinePath']) {
            $values['baselinePath'] = '.symfony-lsp-baseline.json';
        }
        if ($values['strictBaseline'] && null === $values['baselinePath']) {
            throw new InvalidConfigurationException('The --strict-baseline option requires --baseline.');
        }

        /** @var string $format */
        $format = $values['format'];
        /** @var string $workspace */
        $workspace = $values['workspace'];
        /** @var string|null $configurationPath */
        $configurationPath = $values['configurationPath'];
        /** @var list<string>|null $blockingCodes */
        $blockingCodes = $values['blockingCodes'];
        /** @var string|null $baselinePath */
        $baselinePath = $values['baselinePath'];
        /** @var bool $strictBaseline */
        $strictBaseline = $values['strictBaseline'];
        /** @var float $timeout */
        $timeout = $values['timeout'];
        /** @var bool $verbose */
        $verbose = $values['verbose'];
        /** @var bool $listCodes */
        $listCodes = $values['listCodes'];
        /** @var bool $help */
        $help = $values['help'];

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
