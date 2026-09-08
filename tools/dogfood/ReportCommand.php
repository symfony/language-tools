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
          --history-dir DIR    Durable ledger directory (default: .gerard/dogfood/history)
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
            if ($options['record'] && !$options['customHistory'] && !is_dir($this->root.'/.gerard')) {
                throw new \RuntimeException('Recording requires a .gerard workspace or an explicit --history-dir.');
            }
            if ($options['record']) {
                $this->filesystem->mkdir($options['history']);
                $options['history'] = realpath($options['history']) ?: $options['history'];
            }
            $ledger = $options['history'].'/ledger.jsonl';
            $output = $options['output'] ?? ($options['record'] ? $options['history'].'/index.html' : $this->root.'/var/dogfood/report.html');
            if (!\in_array(strtolower(pathinfo($output, \PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                throw new \InvalidArgumentException('The output must be an .html or .htm file.');
            }
            $lock = null;
            if ($options['record']) {
                $lockPath = $this->root.'/var/dogfood/report-locks/'.hash('sha256', $ledger).'.lock';
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
                $imported = is_dir($options['matrix'])
                    ? $this->importer->collect($options['matrix'])
                    : ['entries' => [], 'legacy' => 0, 'warnings' => []];
                if ($options['customMatrix'] && !is_dir($options['matrix'])) {
                    throw new \RuntimeException('The requested matrix directory does not exist.');
                }
                $merged = $this->history->merge($this->history->load($ledger), $imported['entries']);
                if ([] === $merged['entries']) {
                    throw new \RuntimeException('No behavioral dogfood history was found. Run the matrix first.');
                }
                $html = $this->html->render($merged['entries']);
                if ($options['record']) {
                    $this->history->save($ledger, $merged['entries']);
                }
                if (!is_file($output) || $html !== file_get_contents($output)) {
                    $this->filesystem->dumpFile($output, $html);
                }
                if ($options['record']) {
                    fwrite(\STDOUT, \sprintf("Recorded %d new observations, completed %d observations; %d in history.\nLedger: %s\n", $merged['added'], $merged['updated'], \count($merged['entries']), $ledger));
                }
                fwrite(\STDOUT, 'HTML: '.$output."\n");
                if (0 < $imported['legacy']) {
                    fwrite(\STDOUT, \sprintf("Skipped %d legacy probe reports.\n", $imported['legacy']));
                }
                foreach ($imported['warnings'] as $warning) {
                    fwrite(\STDERR, 'Artifact warning: '.$warning."\n");
                }

                return [] === $imported['warnings'] ? 0 : 1;
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
     * @return array{record: bool, help: bool, matrix: string, history: string, output: ?string, customHistory: bool, customMatrix: bool}
     */
    private function options(array $arguments): array
    {
        $options = [
            'record' => false,
            'help' => false,
            'matrix' => $this->root.'/var/dogfood/matrix',
            'history' => $this->root.'/.gerard/dogfood/history',
            'output' => null,
            'customHistory' => false,
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
                $options['customHistory'] = true;
            } else {
                $options['output'] = $value;
            }
        }

        return $options;
    }
}
