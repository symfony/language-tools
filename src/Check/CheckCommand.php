<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Server\SensitiveDataRedactor;

final class CheckCommand
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_DIAGNOSTICS = 10;
    public const EXIT_INVOCATION = 11;
    public const EXIT_OPERATIONAL = 12;

    public function __construct(
        private readonly CheckOptionsParser $optionsParser,
        private readonly CheckRunner $runner,
        private readonly CheckReporter $reporter,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly SensitiveDataRedactor $redactor,
        private readonly string $version,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): CheckExecution
    {
        $format = 'human';
        $verbose = \in_array('--verbose', $arguments, true);
        try {
            $parsed = $this->optionsParser->parse($arguments);
            $format = $parsed->format;
            if ($parsed->value instanceof InvalidConfigurationException) {
                throw $parsed->value;
            }
            $options = $parsed->value;
            if ($options->help) {
                return new CheckExecution(self::EXIT_SUCCESS, $this->reporter->help());
            }
            if ($options->listCodes) {
                return new CheckExecution(self::EXIT_SUCCESS, $this->reporter->codes($this->diagnosticCodes->all(), $format));
            }

            $result = $this->runner->run($options);
            $exitCode = !$result->complete
                ? self::EXIT_OPERATIONAL
                : (0 === $result->blockingCount ? self::EXIT_SUCCESS : self::EXIT_DIAGNOSTICS);
            $stderr = implode('', array_map(
                fn (array $error): string => $this->errorOutput($error, $options->verbose),
                $result->errors,
            ));

            return new CheckExecution($exitCode, $this->reporter->render($result, $format, $options->verbose, $exitCode), $stderr);
        } catch (InvalidConfigurationException $error) {
            $result = $this->errorResult('invocation', $error->getMessage());

            return new CheckExecution(
                self::EXIT_INVOCATION,
                $this->reporter->render($result, $format, $verbose, self::EXIT_INVOCATION),
                $error->getMessage()."\n",
            );
        } catch (CheckOperationalException $error) {
            $result = $this->errorResult('operational', $error->getMessage());

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format, $verbose, self::EXIT_OPERATIONAL),
                $error->getMessage()."\n",
            );
        } catch (\Throwable $error) {
            $message = 'The diagnostics check failed because of an internal error.';
            $result = $this->errorResult('operational', $message, $error);

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format, $verbose, self::EXIT_OPERATIONAL),
                $this->errorOutput($result->errors[0], $verbose),
            );
        }
    }

    private function errorResult(string $category, string $message, ?\Throwable $cause = null): CheckResult
    {
        $error = ['category' => $category, 'message' => $message];
        if (null !== $cause) {
            $workspace = getcwd();
            $error['cause'] = [
                'class' => $cause::class,
                'message' => $this->redactor->redact($cause->getMessage(), false === $workspace ? [] : [$workspace]),
            ];
        }

        return new CheckResult(
            $this->version,
            false,
            [],
            [],
            [],
            null,
            'none',
            false,
            [$error],
            0,
        );
    }

    /** @param array{category: string, message: string, project?: string, provider?: string, cause?: array{class: string, message: string}} $error */
    private function errorOutput(array $error, bool $verbose): string
    {
        $output = (isset($error['project']) ? '['.$error['project'].'] ' : '').$error['message']."\n";
        if ($verbose && isset($error['cause'])) {
            $output .= \sprintf('Cause: %s: %s', $error['cause']['class'], $error['cause']['message'])."\n";
        }

        return $output;
    }
}
