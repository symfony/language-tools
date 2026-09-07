<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Identifies the measured source tree by hashing the relative path and the
 * contents of every PHP file below it, so line numbers recorded before an edit
 * are never unioned with line numbers recorded after it. Uncommitted edits
 * count, which a revision identifier could not express.
 *
 * The coverage bootstrap loads this class before the Composer autoloader
 * exists, so it must stay dependency-free.
 */
final class SourceIdentity
{
    public static function of(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The source directory "%s" does not exist.', $directory));
        }
        $files = self::files($directory, '');
        sort($files, \SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            $contents = hash_file('sha256', $directory.'/'.$file);
            if (false === $contents) {
                throw new \RuntimeException(\sprintf('Unable to read the source file "%s".', $directory.'/'.$file));
            }
            hash_update($context, $file."\0".$contents."\n");
        }

        return 'sha256:'.hash_final($context);
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory, string $prefix): array
    {
        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $files = [...$files, ...self::files($path, $prefix.$entry.'/')];
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $prefix.$entry;
            }
        }

        return $files;
    }
}
