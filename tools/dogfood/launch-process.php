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
$usageFile = getenv('SYMFONY_LSP_DOGFOOD_USAGE_FILE');
putenv('SYMFONY_LSP_DOGFOOD_USAGE_FILE');
passthru('exec '.$command, $exitCode);

if (is_string($usageFile) && '' !== $usageFile && is_array($usage = getrusage(1))) {
    $cpuMilliseconds = 0.0;
    foreach (['ru_utime.tv_sec' => 1000, 'ru_stime.tv_sec' => 1000, 'ru_utime.tv_usec' => 0.001, 'ru_stime.tv_usec' => 0.001] as $field => $factor) {
        $value = $usage[$field] ?? 0;
        $cpuMilliseconds += (is_int($value) ? $value : 0) * $factor;
    }
    @file_put_contents($usageFile, json_encode(['cpuMilliseconds' => round($cpuMilliseconds, 1)]));
}

exit($exitCode);
