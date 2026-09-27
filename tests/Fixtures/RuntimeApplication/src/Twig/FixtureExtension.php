<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class FixtureExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('fixture_runtime', [FixtureRuntime::class, 'format'], ['needs_context' => true]),
            new TwigFunction('fixture_inherited', [FixtureRuntime::class, 'label'], ['needs_environment' => true]),
            new TwigFunction('fixture_static', '\\'.self::class.'::staticFunction'),
            new TwigFunction('fixture_invokable', new FixtureInvokable()),
            new TwigFunction('fixture_closure', static fn (): string => ''),
            new TwigFunction('fixture_dynamic_*', [$this, 'dynamic']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('fixture_object', [$this, 'objectFilter'], ['is_variadic' => true]),
            new TwigFilter('fixture_first_class', $this->firstClass(...), ['needs_charset' => true]),
            new TwigFilter('fixture_trait', [FixtureRuntime::class, 'shout']),
            new TwigFilter('fixture_global', 'strtoupper'),
            new TwigFilter('fixture_missing', [FixtureRuntime::class, 'missing']),
        ];
    }

    public static function staticFunction(): string
    {
        return '';
    }

    public function objectFilter(string $value, string ...$suffixes): string
    {
        return $value.implode('', $suffixes);
    }

    public function dynamic(string $suffix): string
    {
        return $suffix;
    }

    private function firstClass(string $charset, string $value): string
    {
        return $value;
    }
}
