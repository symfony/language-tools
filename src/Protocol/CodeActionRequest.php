<?php

namespace Symfony\Lsp\Protocol;

final class CodeActionRequest extends DocumentRequest
{
    /** @param list<CodeActionDiagnostic> $diagnostics */
    public function __construct(
        DocumentRequest $request,
        private readonly array $diagnostics,
    ) {
        parent::__construct($request->document, $request->project, $request->source);
    }

    /** @return list<CodeActionDiagnostic> */
    public function diagnostics(string ...$codes): array
    {
        if ([] === $codes) {
            return $this->diagnostics;
        }

        return array_values(array_filter(
            $this->diagnostics,
            static fn (CodeActionDiagnostic $diagnostic): bool => \in_array($diagnostic->code, $codes, true),
        ));
    }
}
