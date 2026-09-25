<?php

namespace Symfony\Lsp\Check;

final class CheckReporter
{
    private const DEFAULT_FORMAT = 'human';

    /** @var array<string, CheckReportFormatInterface> */
    private readonly array $formats;

    /** @param iterable<CheckReportFormatInterface> $formats */
    public function __construct(
        private readonly CheckReportViewBuilder $viewBuilder,
        iterable $formats,
    ) {
        $indexed = [];
        foreach ($formats as $format) {
            $indexed[$format->name()] = $format;
        }
        $this->formats = $indexed;
    }

    public function render(CheckResult $result, string $format, bool $verbose, int $exitCode): string
    {
        return $this->format($format)->render($this->viewBuilder->build($result, $exitCode), $verbose);
    }

    /** @param list<string> $codes */
    public function codes(array $codes, string $format): string
    {
        return $this->format($format)->codes($codes);
    }

    private function format(string $name): CheckReportFormatInterface
    {
        $format = $this->formats[$name] ?? $this->formats[self::DEFAULT_FORMAT] ?? null;
        if (null === $format) {
            throw new \LogicException('No check report format is registered.');
        }

        return $format;
    }
}
