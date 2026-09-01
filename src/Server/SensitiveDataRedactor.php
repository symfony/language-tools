<?php

namespace Symfony\Lsp\Server;

use Symfony\Component\Filesystem\Path;

final class SensitiveDataRedactor
{
    public function __construct(
        private readonly Utf8StringTruncator $truncator = new Utf8StringTruncator(),
    ) {
    }

    /** @param list<string> $roots */
    public function redact(string $value, array $roots = []): string
    {
        $value = mb_scrub($value, 'UTF-8');
        foreach ($roots as $root) {
            $root = Path::canonicalize($root);
            $value = str_replace([$root, str_replace('/', '\\', $root)], '.', $value);
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = preg_replace('/\b[A-Z][A-Z0-9_]{2,}\s*=\s*[^\s,;]+/', '[redacted]', $value) ?? '[redacted]';
        $value = preg_replace('/\b[a-z][a-z0-9+.-]*:\/\/[^\s\/:@]+:[^\s\/@]+@/i', '[redacted]@', $value) ?? '[redacted]';
        $value = preg_replace('/\bauthorization\s*[=:]\s*[^\r\n,;]+/i', 'authorization=[redacted]', $value) ?? '[redacted]';
        $value = preg_replace(
            '/\b(password|passwd|secret|token|credential|cookie|api[_-]?key|private[_-]?key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $value,
        ) ?? '[redacted]';
        $value = preg_replace(
            '/\b(?=[A-Za-z0-9_-]*[_-])[A-Za-z0-9_-]*(?:password|passwd|secret|token|authorization|credential|cookie|api[_-]?key|private[_-]?key)[A-Za-z0-9_-]*\b/i',
            '[redacted]',
            $value,
        ) ?? '[redacted]';

        return $this->truncator->truncate($value, 500);
    }
}
