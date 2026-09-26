<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Index\NamedSourceSymbolInterface;

final class ReferencesRequest extends PositionedRequest
{
    public function __construct(
        PositionedRequest $request,
        public readonly bool $includeDeclaration,
    ) {
        parent::__construct($request, $request->position, $request->offset);
    }

    /**
     * The symbols to report, without the declarations when the client asked
     * for the references alone.
     *
     * @template TSymbol of NamedSourceSymbolInterface
     *
     * @param iterable<TSymbol> $symbols
     *
     * @return list<TSymbol>
     */
    public function reported(iterable $symbols): array
    {
        $reported = [];
        foreach ($symbols as $symbol) {
            if ($this->includeDeclaration || !$symbol->declaration) {
                $reported[] = $symbol;
            }
        }

        return $reported;
    }
}
