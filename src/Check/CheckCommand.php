<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;

/** @phpstan-import-type CheckError from CheckResult */
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
        private readonly CheckErrorCauseRenderer $causes,
        private readonly CheckProfiler $profiler,
        private readonly CheckProfileReporter $profileReporter,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly SensitiveDataRedactor $redactor,
        private readonly ServerLogger $logger,
        private readonly string $version,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, int|float|null $processStartedAt = null): CheckExecution
    {
        $format = 'human';
        $verbose = false;
        try {
            $options = $this->optionsParser->parse($arguments);
            $format = $options->format;
            $verbose = $options->verbose;
            if (null !== $options->error) {
                throw $options->error;
            }
            $this->logger->configure($verbose ? 'verbose' : 'off');
            if ($options->help) {
                return new CheckExecution(self::EXIT_SUCCESS, $this->optionsParser->help());
            }
            if ($options->listCodes) {
                return new CheckExecution(self::EXIT_SUCCESS, $this->reporter->codes($this->diagnosticCodes->all(), $format));
            }

            $this->profiler->start($options->profile, $processStartedAt);
            $result = $this->runner->run($options);
            $exitCode = !$result->complete
                ? self::EXIT_OPERATIONAL
                : (0 === $result->blockingCount ? self::EXIT_SUCCESS : self::EXIT_DIAGNOSTICS);
            $stderr = implode('', array_map(
                fn (array $error): string => $this->errorOutput($error, $verbose),
                $result->errors,
            ));
            $stderr .= $this->profileReporter->render($result);

            return new CheckExecution($exitCode, $this->reporter->render($result, $format, $verbose, $exitCode), $stderr);
        } catch (InvalidConfigurationException $error) {
            $result = $this->errorResult('invocation', $error->getMessage());

            return new CheckExecution(
                self::EXIT_INVOCATION,
                $this->reporter->render($result, $format, $verbose, self::EXIT_INVOCATION),
                $error->getMessage()."\n".$this->profileReporter->render($result),
            );
        } catch (CheckOperationalException $error) {
            $result = $this->errorResult('operational', $error->getMessage());

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format, $verbose, self::EXIT_OPERATIONAL),
                $error->getMessage()."\n".$this->profileReporter->render($result),
            );
        } catch (\Throwable $error) {
            $message = 'The diagnostics check failed because of an internal error.';
            $result = $this->errorResult('operational', $message, $error);

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format, $verbose, self::EXIT_OPERATIONAL),
                $this->errorOutput($result->errors[0], $verbose).$this->profileReporter->render($result),
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
            $this->profiler->finish(),
        );
    }

    /** @param CheckError $error */
    private function errorOutput(array $error, bool $verbose): string
    {
        $output = (isset($error['project']) ? '['.$error['project'].'] ' : '').$error['message']."\n";
        if (isset($error['cause'])) {
            $output .= $verbose
                ? implode("\n", $this->causes->lines($error['cause']))."\n"
                : "Add --verbose to show the cause.\n";
        }

        return $output;
    }
}
