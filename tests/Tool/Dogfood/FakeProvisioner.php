<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ProvisionerInterface;
use Symfony\Lsp\Tools\Dogfood\ProvisioningException;

final class FakeProvisioner implements ProvisionerInterface
{
    /** @var list<string> */
    public array $released = [];

    public function __construct(
        private string $checkout,
        private ?ProvisioningException $failure = null,
    ) {
    }

    public function provision(ProjectConfiguration $configuration): string
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->checkout;
    }

    public function release(ProjectConfiguration $configuration): void
    {
        $this->released[] = $configuration->name;
    }
}
