<?php

/*
 * Starts execution coverage before any language server code runs.
 *
 * tools/dogfood-coverage prepends this file with auto_prepend_file, which is
 * the only reliable moment to install the coverage filter: Xdebug tags
 * executable units when a file is compiled, so a filter set later would miss
 * everything the server already loaded.
 *
 * Nothing is ever written to standard output: the server speaks JSON-RPC there.
 */

(static function (): void {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', '');

    $fail = static function (string $message): never {
        fwrite(\STDERR, 'dogfood coverage: '.$message."\n");
        exit(3);
    };

    $problems = [];
    if (!function_exists('xdebug_start_code_coverage') || !function_exists('xdebug_get_code_coverage')) {
        $problems[] = 'no coverage driver is available; install Xdebug (nothing is installed automatically) or run the matrix without --server tools/dogfood-coverage.';
    }
    if (!function_exists('symfony_lsp_tree_sitter_parse')) {
        $problems[] = 'the Tree-sitter extension is not loaded, so the server would re-exec itself and drop the instrumentation; start it through tools/dogfood-coverage.';
    }
    $directory = getenv('SYMFONY_LSP_COVERAGE_DIR');
    if (!is_string($directory) || '' === $directory || !is_dir($directory) || !is_writable($directory)) {
        $problems[] = 'SYMFONY_LSP_COVERAGE_DIR must point at a writable directory; start the server through tools/dogfood-coverage.';
        $directory = '';
    }
    if ([] !== $problems) {
        $fail(implode("\ndogfood coverage: ", $problems));
    }

    $root = dirname(__DIR__, 2);
    $sourceRoot = $root.'/src/';
    if (function_exists('xdebug_set_filter')) {
        xdebug_set_filter(\XDEBUG_FILTER_CODE_COVERAGE, \XDEBUG_PATH_INCLUDE, [$sourceRoot]);
    }
    $flags = \XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE;
    if (defined('XDEBUG_CC_BRANCH_CHECK')) {
        $flags |= \XDEBUG_CC_BRANCH_CHECK;
    }
    xdebug_start_code_coverage($flags);
    if (function_exists('xdebug_code_coverage_started') && !xdebug_code_coverage_started()) {
        $fail('the coverage driver refused to start; run PHP with xdebug.mode=coverage.');
    }

    register_shutdown_function(static function () use ($directory, $root, $sourceRoot): void {
        $files = [];
        foreach (xdebug_get_code_coverage() as $path => $data) {
            if (!is_string($path) || !str_starts_with($path, $sourceRoot) || !is_array($data)) {
                continue;
            }
            $lines = is_array($data['lines'] ?? null) ? $data['lines'] : $data;
            $executed = [];
            $unexecuted = [];
            foreach ($lines as $line => $state) {
                if (!is_int($line)) {
                    continue;
                }
                if (1 === $state) {
                    $executed[] = $line;
                } elseif (-1 === $state) {
                    $unexecuted[] = $line;
                }
            }
            sort($executed);
            sort($unexecuted);
            $branches = [];
            foreach (is_array($data['functions'] ?? null) ? $data['functions'] : [] as $function => $information) {
                if (!is_array($information) || !is_array($information['branches'] ?? null)) {
                    continue;
                }
                foreach ($information['branches'] as $operation => $branch) {
                    if (!is_array($branch) || !is_int($branch['line_start'] ?? null)) {
                        continue;
                    }
                    $branches[] = [
                        'function' => (string) $function,
                        'op' => (int) $operation,
                        'line' => $branch['line_start'],
                        'hit' => 0 !== ($branch['hit'] ?? 0),
                    ];
                }
            }
            $files[substr($path, strlen($root) + 1)] = [
                'executed' => $executed,
                'unexecuted' => $unexecuted,
                'branches' => $branches,
            ];
        }
        ksort($files, \SORT_STRING);

        // One artifact per process, published with an atomic rename so a
        // concurrent report never reads a half-written file.
        $name = sprintf('coverage-%d-%s.json', getmypid(), bin2hex(random_bytes(6)));
        $temporary = $directory.'/.'.$name.'.part';
        try {
            $document = json_encode(['format' => 'symfony-lsp-coverage/1', 'files' => $files], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            fwrite(\STDERR, 'dogfood coverage: unable to encode the coverage artifact: '.$e->getMessage()."\n");

            return;
        }
        if (false === file_put_contents($temporary, $document) || !rename($temporary, $directory.'/'.$name)) {
            @unlink($temporary);
            fwrite(\STDERR, sprintf('dogfood coverage: unable to write the coverage artifact to "%s".%s', $directory, "\n"));
        }
    });
})();
