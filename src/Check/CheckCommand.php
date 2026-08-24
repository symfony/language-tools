<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckCommand
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_DIAGNOSTICS = 1;
    public const EXIT_INVOCATION = 2;
    public const EXIT_OPERATIONAL = 3;

    public function __construct(
        private readonly CheckOptionsParser $optionsParser,
        private readonly CheckRunner $runner,
        private readonly CheckReporter $reporter,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly string $version,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): CheckExecution
    {
        $format = 'human';
        try {
            $format = $this->optionsParser->selectedFormat($arguments);
            $options = $this->optionsParser->parse($arguments);
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
                static fn (array $error): string => (isset($error['project']) ? '['.$error['project'].'] ' : '').$error['message']."\n",
                $result->errors,
            ));

            return new CheckExecution($exitCode, $this->reporter->render($result, $format), $stderr);
        } catch (InvalidConfigurationException $error) {
            $result = $this->errorResult('invocation', $error->getMessage());

            return new CheckExecution(
                self::EXIT_INVOCATION,
                $this->reporter->render($result, $format),
                $error->getMessage()."\n",
            );
        } catch (CheckOperationalException $error) {
            $result = $this->errorResult('operational', $error->getMessage());

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format),
                $error->getMessage()."\n",
            );
        } catch (\Throwable) {
            $message = 'The diagnostics check failed because of an internal error.';
            $result = $this->errorResult('operational', $message);

            return new CheckExecution(
                self::EXIT_OPERATIONAL,
                $this->reporter->render($result, $format),
                $message."\n",
            );
        }
    }

    private function errorResult(string $category, string $message): CheckResult
    {
        return new CheckResult(
            $this->version,
            false,
            [],
            [],
            [],
            null,
            'none',
            false,
            [['category' => $category, 'message' => $message]],
            0,
        );
    }
}
