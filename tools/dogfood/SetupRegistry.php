<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class SetupRegistry
{
    /**
     * @param array<string, SetupInterface> $setups
     */
    public function __construct(
        private array $setups,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = array_map(strval(...), array_keys($this->setups));
        sort($ids);

        return $ids;
    }

    public function get(string $id): SetupInterface
    {
        return $this->setups[$id] ?? throw new ConfigurationException(\sprintf('Unknown setup "%s".', $id));
    }
}
