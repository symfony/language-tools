<?php

$logFile = $argv[1] ?? null;
if (!is_string($logFile)) {
    exit(1);
}

file_put_contents($logFile, "start\n", \FILE_APPEND | \LOCK_EX);
$process = proc_open(
    [\PHP_BINARY, ...array_slice($argv, 2)],
    [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
    $pipes,
);
if (!is_resource($process)) {
    exit(1);
}

$exitCode = proc_close($process);
file_put_contents($logFile, "finish\n", \FILE_APPEND | \LOCK_EX);

exit($exitCode);
