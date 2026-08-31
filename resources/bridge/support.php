<?php

/** @return list<string> */
function symfonyLspBridgeExcludedDirectories(): array
{
    return ['.git', 'node_modules', 'var', 'vendor'];
}

function symfonyLspBridgeFinalizeSection(array $section): array
{
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}

/** @return list<string>|null */
function symfonyLspBridgeSupportedVersions(string $url, string $cache): ?array
{
    $cached = is_file($cache) ? @file_get_contents($cache) : false;
    $attempt = $cache.'.last-attempt';
    $attemptedAt = is_file($attempt) ? @filemtime($attempt) : false;
    if (is_int($attemptedAt) && $attemptedAt >= time() - 3600) {
        return is_string($cached) ? symfonyLspBridgeDecodeSupportedVersions($cached) : null;
    }
    @touch($attempt);

    $context = stream_context_create(['http' => [
        'timeout' => 2.0,
        'follow_location' => true,
        'max_redirects' => 3,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: Symfony-Language-Tools\r\n",
    ]]);
    $metadata = @file_get_contents($url, false, $context);
    if (is_string($metadata)) {
        $versions = symfonyLspBridgeDecodeSupportedVersions($metadata);
        if (null !== $versions) {
            $temporary = $cache.'.'.getmypid().'.tmp';
            if (false !== @file_put_contents($temporary, $metadata, LOCK_EX)) {
                if (!@rename($temporary, $cache)) {
                    @unlink($temporary);
                }
            }

            return $versions;
        }
    }

    return is_string($cached) ? symfonyLspBridgeDecodeSupportedVersions($cached) : null;
}

/** @param list<string> $supportedVersions */
function symfonyLspBridgeSupportsBranch(string $branch, array $supportedVersions): bool
{
    if ([] === $supportedVersions) {
        return false;
    }

    $oldest = $newest = $supportedVersions[0];
    foreach ($supportedVersions as $version) {
        if (version_compare($version, $oldest, '<')) {
            $oldest = $version;
        }
        if (version_compare($version, $newest, '>')) {
            $newest = $version;
        }
    }

    return version_compare($branch, $oldest, '>=') && version_compare($branch, $newest, '<=');
}

/** @return list<string>|null */
function symfonyLspBridgeDecodeSupportedVersions(string $metadata): ?array
{
    try {
        $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    $supported = is_array($decoded) ? ($decoded['supported_versions'] ?? null) : null;
    if (!is_array($supported) || [] === $supported) {
        return null;
    }

    $versions = [];
    foreach ($supported as $version) {
        if (!is_string($version) || 1 !== preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version)) {
            return null;
        }
        $versions[$version] = true;
    }

    return array_keys($versions);
}

function symfonyLspBridgeRunJsonCommand(object $application, array $arguments): array
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

    $result = json_decode(symfonyLspBridgeCommandJsonDocument($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
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
function symfonyLspBridgeCommandJsonDocument(string $output): string
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

function symfonyLspBridgeSplitDebugValues(mixed $value): array
{
    if (!is_string($value) || '' === $value || 'ANY' === $value) {
        return [];
    }

    return preg_split('/[|, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
