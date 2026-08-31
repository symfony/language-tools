<?php

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 2 > count($arguments)) {
    fwrite(\STDERR, "Missing process command.\n");
    exit(125);
}

if (!function_exists('posix_setsid') || -1 === posix_setsid()) {
    fwrite(\STDERR, "Unable to isolate the process from the controlling terminal.\n");
    exit(125);
}

$escapedArguments = [];
foreach (array_slice($arguments, 1) as $argument) {
    if (!is_string($argument)) {
        fwrite(\STDERR, "Invalid process command.\n");
        exit(125);
    }
    $escapedArguments[] = escapeshellarg($argument);
}
$command = implode(' ', $escapedArguments);
passthru('exec '.$command, $exitCode);

exit($exitCode);
