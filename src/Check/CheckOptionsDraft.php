<?php

namespace Symfony\Lsp\Check;

final class CheckOptionsDraft
{
    public string $format = 'human';
    public ?string $selectedFormat = null;
    public ?string $configurationPath = null;
    /** @var list<string> */
    public array $selectors = [];
    /** @var list<string> */
    public array $projectRoots = [];
    /** @var array<string, mixed> */
    public array $overrides = [];
    /** @var list<string>|null */
    public ?array $blockingCodes = null;
    public ?string $baselinePath = null;
    public string $baselineMode = 'none';
    public bool $strictBaseline = false;
    public float $timeout = 600.0;
    public bool $verbose = false;
    public bool $profile = false;
    public bool $listCodes = false;
    public bool $help = false;

    public function __construct(public string $workspace)
    {
    }
}
