<?php

function runJsonCommand(object $application, array $arguments): array
{
    static $cache = [];

    $cacheKey = hash('sha256', serialize($arguments));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $exitCode = $application->run(new Symfony\Component\Console\Input\ArrayInput($arguments), $output);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('%s exited with status %d.', $arguments['command'], $exitCode));
    }

    $result = json_decode(firstJsonDocument($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException(sprintf('%s did not return a JSON object or array.', $arguments['command']));
    }

    return $cache[$cacheKey] = $result;
}

function firstJsonDocument(string $output): string
{
    for ($start = 0, $length = strlen($output); $start < $length; ++$start) {
        if ('{' !== $output[$start] && '[' !== $output[$start]) {
            continue;
        }

        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($index = $start; $index < $length; ++$index) {
            $character = $output[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ('"' === $character) {
                    $quoted = false;
                }
                continue;
            }
            if ('"' === $character) {
                $quoted = true;
            } elseif ('{' === $character || '[' === $character) {
                ++$depth;
            } elseif ('}' === $character || ']' === $character) {
                if (0 !== --$depth) {
                    continue;
                }

                $document = substr($output, $start, $index - $start + 1);
                json_decode($document);
                if (\JSON_ERROR_NONE === json_last_error()) {
                    return $document;
                }
                break;
            }
        }
    }

    return $output;
}

function splitDebugValues(mixed $value): array
{
    if (!is_string($value) || '' === $value || 'ANY' === $value) {
        return [];
    }

    return preg_split('/[|, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
