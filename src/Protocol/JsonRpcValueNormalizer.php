<?php

namespace Symfony\Lsp\Protocol;

final class JsonRpcValueNormalizer
{
    /**
     * @param array<array-key, mixed>|\stdClass|null $params
     *
     * @return array<array-key, mixed>
     */
    public function normalizeParams(array|\stdClass|null $params): array
    {
        return $this->normalizeArray($params instanceof \stdClass ? get_object_vars($params) : $params ?? []);
    }

    public function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return \is_array($value) ? $this->normalizeArray($value) : $value;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->normalize($value);
        }

        return $values;
    }
}
