<?php

namespace Symfony\Lsp\Tests\Support;

final class LanguageServerTranscriptAction
{
    /** @param \Closure(): void $action */
    public function __construct(public readonly \Closure $action)
    {
    }

    public function run(): void
    {
        ($this->action)();
    }
}
