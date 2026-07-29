<?php

namespace Symfony\Lsp\Protocol;

use Amp\Cancellation;

interface MessageReaderInterface
{
    public function read(?Cancellation $cancellation = null): ?string;
}
