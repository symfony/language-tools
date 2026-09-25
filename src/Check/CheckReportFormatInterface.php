<?php

namespace Symfony\Lsp\Check;

interface CheckReportFormatInterface
{
    public function name(): string;

    public function render(CheckReportView $view, bool $verbose): string;

    /** @param list<string> $codes */
    public function codes(array $codes): string;
}
