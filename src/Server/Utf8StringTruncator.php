<?php

namespace Symfony\Lsp\Server;

final class Utf8StringTruncator
{
    public function truncate(string $value, int $limit): string
    {
        if ($limit < 3) {
            throw new \InvalidArgumentException('The truncation limit must be at least three bytes.');
        }
        if (\strlen($value) <= $limit) {
            return $value;
        }
        $value = substr($value, 0, $limit - 3);
        while ('' !== $value && 1 !== preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }

        return $value.'...';
    }
}
