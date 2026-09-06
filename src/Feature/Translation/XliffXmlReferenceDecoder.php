<?php

namespace Symfony\Lsp\Feature\Translation;

final class XliffXmlReferenceDecoder
{
    public function decode(string $value): string
    {
        return html_entity_decode(str_replace('&#X', '&amp;#X', $value), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }
}
