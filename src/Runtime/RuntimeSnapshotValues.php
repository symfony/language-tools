<?php

namespace Symfony\Lsp\Runtime;

final class RuntimeSnapshotValues
{
    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
