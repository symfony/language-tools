<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class ServiceIndex
{
    /** @var array<string, Service> */
    private array $services = [];

    private bool $complete = false;

    public function replace(bool $complete, Service ...$services): void
    {
        $this->services = [];
        foreach ($services as $service) {
            $this->services[$service->id()] = $service;
        }
        ksort($this->services);
        $this->complete = $complete;
    }

    public function get(string $id): ?Service
    {
        return $this->services[$id] ?? null;
    }

    /**
     * @return list<Service>
     */
    public function matching(string $prefix): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (Service $service): bool => str_starts_with($service->id(), $prefix),
        ));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
