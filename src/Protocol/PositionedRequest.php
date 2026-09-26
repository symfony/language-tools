<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\Position;

class PositionedRequest extends DocumentRequest
{
    public function __construct(
        DocumentRequest $request,
        public readonly Position $position,
        public readonly int $offset,
    ) {
        parent::__construct($request->document, $request->project, $request->source);
    }
}
