<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\Color;
use Symfony\Lsp\Document\Range;

final class ColorPresentationRequest extends DocumentRequest
{
    public function __construct(
        DocumentRequest $request,
        public readonly Range $range,
        public readonly Color $color,
    ) {
        parent::__construct($request->document, $request->project, $request->source);
    }
}
