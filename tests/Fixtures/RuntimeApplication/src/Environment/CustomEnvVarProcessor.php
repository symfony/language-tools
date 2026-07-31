<?php

namespace App\Environment;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class CustomEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return strtoupper((string) $getEnv($name));
    }

    public static function getProvidedTypes(): array
    {
        return ['fixture_upper' => 'string'];
    }
}
