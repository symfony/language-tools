<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class TemplateReference implements RangedSourceSymbolInterface
{
    /**
     * @param list<string> $variables
     * @param list<string> $requiredParentClassNames
     */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly array $variables = [],
        public readonly ?string $receiverClassName = null,
        public readonly array $requiredParentClassNames = [],
    ) {
    }
}
