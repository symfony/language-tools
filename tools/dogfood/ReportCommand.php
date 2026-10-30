<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ReportCommand
{
    private const USAGE = <<<'TEXT'
        Usage: tools/dogfood-report [options]

        Generate an offline HTML page showing behavioral dogfooding progress.

        Options:
          --record             Persist observations and regenerate the history page
          --matrix-dir DIR     Artifact collection or single timestamped run
          --history-dir DIR    Durable ledger directory (required with --record)
          --output FILE        HTML destination (default with --record: history/index.html;
                               otherwise: var/dogfood/report.html)
          --help               Show this help

        Recording is idempotent. It never changes or approves scenario expectations.
        Legacy probe scores are not imported. Missing metrics remain unknown; older
        artifacts without expectation fingerprints are not strictly comparable.
        TEXT;

    public function __construct(
        private readonly string $root,
        private readonly ReportImporter $importer,
        private readonly ReportHistory $history,
        private readonly HistoryHtmlReport $html,
        private readonly Filesystem $filesystem,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = $this->options($arguments);
            if ($options['help']) {
                fwrite(\STDOUT, self::USAGE."\n");

                return 0;
            }
            $history = $options['history'];
            if ($options['record']) {
                if (null === $history) {
                    throw new \RuntimeException('Recording requires an explicit --history-dir.');
                }
                $this->filesystem->mkdir($history);
                $history = realpath($history) ?: $history;
            }
            $ledger = null === $history ? null : $history.'/ledger.jsonl';
            $recordedLedger = $options['record'] ? $ledger : null;
            $output = $options['output'] ?? (null !== $recordedLedger ? $history.'/index.html' : $this->root.'/var/dogfood/report.html');
            if (!\in_array(strtolower(pathinfo($output, \PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                throw new \InvalidArgumentException('The output must be an .html or .htm file.');
            }
            $lock = null;
            if (null !== $recordedLedger) {
                $lockPath = $this->root.'/var/dogfood/report-locks/'.hash('sha256', $recordedLedger).'.lock';
                $this->filesystem->mkdir(\dirname($lockPath));
                $lock = fopen($lockPath, 'c');
                if (false === $lock) {
                    throw new \RuntimeException('Unable to open the dogfood history lock.');
                }
                if (!flock($lock, \LOCK_EX)) {
                    fclose($lock);
                    throw new \RuntimeException('Unable to lock the dogfood history ledger.');
                }
            }
            try {
                $existing = null === $ledger ? [] : $this->history->load($ledger);
                $finalized = [];
                foreach ($existing as $entry) {
                    if ($entry['finalized']) {
                        $finalized[$entry['run'].'/'.$entry['project']] = true;
                    }
                }
                $imported = is_dir($options['matrix'])
                    ? $this->importer->collect($options['matrix'])
                    : ['entries' => [], 'legacy' => 0, 'warnings' => []];
                if ($options['customMatrix'] && !is_dir($options['matrix'])) {
                    throw new \RuntimeException('The requested matrix directory does not exist.');
                }
                $warnings = array_values(array_filter($imported['warnings'], static fn (string $warning): bool => !isset($finalized[explode(': ', $warning, 2)[0]])));
                $merged = $this->history->merge($existing, $imported['entries']);
                if ([] === $merged['entries']) {
                    throw new \RuntimeException('No behavioral dogfood history was found. Run the matrix first.');
                }
                $html = $this->html->render($merged['entries']);
                if (null !== $recordedLedger) {
                    $this->history->save($recordedLedger, $merged['entries']);
                }
                if (!is_file($output) || $html !== file_get_contents($output)) {
                    $this->filesystem->dumpFile($output, $html);
                }
                if (null !== $recordedLedger) {
                    fwrite(\STDOUT, \sprintf("Recorded %d new observation%s, updated %d observation%s; %d in history.\nLedger: %s\n", $merged['added'], 1 === $merged['added'] ? '' : 's', $merged['updated'], 1 === $merged['updated'] ? '' : 's', \count($merged['entries']), $recordedLedger));
                }
                fwrite(\STDOUT, 'HTML: '.$output."\n");
                if (0 < $imported['legacy']) {
                    fwrite(\STDOUT, \sprintf("Skipped %d legacy probe reports.\n", $imported['legacy']));
                }
                foreach ($warnings as $warning) {
                    fwrite(\STDERR, 'Artifact warning: '.$warning."\n");
                }

                return [] === $warnings ? 0 : 1;
            } finally {
                if (\is_resource($lock)) {
                    flock($lock, \LOCK_UN);
                    fclose($lock);
                }
            }
        } catch (\Throwable $error) {
            fwrite(\STDERR, $error->getMessage()."\n");

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{record: bool, help: bool, matrix: string, history: ?string, output: ?string, customMatrix: bool}
     */
    private function options(array $arguments): array
    {
        $options = [
            'record' => false,
            'help' => false,
            'matrix' => $this->root.'/var/dogfood/matrix',
            'history' => null,
            'output' => null,
            'customMatrix' => false,
        ];
        while (null !== $argument = array_shift($arguments)) {
            if ('--record' === $argument) {
                $options['record'] = true;
                continue;
            }
            if ('--help' === $argument || '-h' === $argument) {
                $options['help'] = true;
                continue;
            }
            [$name, $inline] = array_pad(explode('=', $argument, 2), 2, null);
            if (!\in_array($name, ['--matrix-dir', '--history-dir', '--output'], true)) {
                throw new \InvalidArgumentException('Unknown report option. Use --help for usage.');
            }
            $value = $inline ?? array_shift($arguments);
            if (null === $value || '' === $value || str_starts_with($value, '--') || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('A report path option requires a value.');
            }
            $value = Path::makeAbsolute($value, getcwd() ?: $this->root);
            if ('--matrix-dir' === $name) {
                $options['matrix'] = $value;
                $options['customMatrix'] = true;
            } elseif ('--history-dir' === $name) {
                $options['history'] = $value;
            } else {
                $options['output'] = $value;
            }
        }

        return $options;
    }
}
