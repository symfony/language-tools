<?php

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'Search')]
final class Search
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public string $query = '';

    #[LiveAction]
    public function submit(): void
    {
        $this->emit('search:completed');
    }

    #[LiveListener('search:completed')]
    public function refresh(): void
    {
    }
}
