<?php

namespace Symfony\Lsp\Protocol;

final class RenameRequest extends PositionedRequest
{
    public function __construct(
        PositionedRequest $request,
        public readonly string $newName,
    ) {
        parent::__construct($request, $request->position, $request->offset);
    }
}
