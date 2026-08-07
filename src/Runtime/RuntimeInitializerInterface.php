<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Symfony\Lsp\Project\Project;

interface RuntimeInitializerInterface
{
    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void;
}
