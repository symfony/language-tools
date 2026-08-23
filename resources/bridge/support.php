<?php

function finalizeBridgeSection(array $section): array
{
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}

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

    $result = json_decode(commandJsonDocument($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException(sprintf('%s did not return a JSON object or array.', $arguments['command']));
    }

    return $cache[$cacheKey] = $result;
}

/*
 * Commands may interleave console logging with their payload. Only JSON that
 * begins a line can be the command document; JSON embedded in a log line is
 * context data.
 */
function commandJsonDocument(string $output): string
{
    $best = null;
    for ($start = 0, $length = strlen($output); $start < $length; ++$start) {
        if ('{' !== $output[$start] && '[' !== $output[$start]) {
            continue;
        }
        $lineStart = strrpos(substr($output, 0, $start), "\n");
        $prefix = substr($output, false === $lineStart ? 0 : $lineStart + 1, $start - (false === $lineStart ? 0 : $lineStart + 1));
        if ('' !== trim($prefix)) {
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
                    if (null === $best || strlen($document) > strlen($best)) {
                        $best = $document;
                    }
                    $start = $index;
                }
                break;
            }
        }
    }

    return $best ?? $output;
}

function splitDebugValues(mixed $value): array
{
    if (!is_string($value) || '' === $value || 'ANY' === $value) {
        return [];
    }

    return preg_split('/[|, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
