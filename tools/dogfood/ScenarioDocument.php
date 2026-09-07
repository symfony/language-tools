<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Document\Position;

final class ScenarioDocument
{
    /**
     * @param string   $file         project-relative path of the resolved file
     * @param Position $position     cursor position, in UTF-16 code units
     * @param int      $anchorOffset byte offset where the anchor starts
     * @param int      $byteOffset   byte offset of the cursor, inside the anchor
     */
    public function __construct(
        public readonly string $file,
        public readonly string $uri,
        public readonly string $text,
        public readonly string $languageId,
        public readonly Position $position,
        public readonly int $anchorOffset,
        public readonly int $byteOffset,
    ) {
    }
}
