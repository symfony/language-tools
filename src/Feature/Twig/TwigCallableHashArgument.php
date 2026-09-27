<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableHashArgument
{
    /**
     * @param list<string> $path     Keys leading from the argument's hash to the hash being edited
     * @param list<string> $usedKeys Keys already written before the cursor in the hash being edited
     * @param string|null  $key      Key whose string value is being edited, null when a key is being edited
     * @param string|null  $quote    Quote opened before the prefix, null for a bare key
     */
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $callee,
        public readonly ?int $position,
        public readonly ?string $name,
        public readonly array $path,
        public readonly array $usedKeys,
        public readonly ?string $key,
        public readonly ?string $quote,
        public readonly string $prefix,
    ) {
    }
}
