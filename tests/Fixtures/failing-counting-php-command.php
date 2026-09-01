<?php

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$logFile = $arguments[1] ?? null;
$failureMarker = $arguments[2] ?? null;
if (!is_string($logFile) || !is_string($failureMarker)) {
    exit(1);
}

file_put_contents($logFile, "start\n", \FILE_APPEND | \LOCK_EX);
if (!is_file($failureMarker)) {
    touch($failureMarker);
    file_put_contents($logFile, "finish\n", \FILE_APPEND | \LOCK_EX);

    exit(1);
}

$process = proc_open(
    [\PHP_BINARY, ...array_slice($arguments, 3)],
    [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
    $pipes,
);
if (!is_resource($process)) {
    exit(1);
}

$exitCode = proc_close($process);
file_put_contents($logFile, "finish\n", \FILE_APPEND | \LOCK_EX);

exit($exitCode);
