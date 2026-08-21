<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;

final class SourceIndexPayloadCodec
{
    private const SHARED_CLASSES = [
        Position::class,
        Range::class,
    ];

    /** @var array<string, list<class-string>> */
    private array $allowedClasses = [];

    /** @param iterable<SourceIndexProviderInterface> $providers */
    public function validate(iterable $providers): void
    {
        $owners = array_fill_keys(self::SHARED_CLASSES, self::class);
        $schemas = [];
        foreach ($providers as $provider) {
            $name = $provider->name();
            if (isset($schemas[$name])) {
                throw new \InvalidArgumentException(\sprintf('The source index provider name "%s" is duplicated.', $name));
            }
            $classes = $provider->payloadClasses();
            if ([] === $classes) {
                throw new \InvalidArgumentException(\sprintf('The source index provider "%s" has an empty payload schema.', $name));
            }
            $schema = [];
            foreach ($classes as $class) {
                if (!class_exists($class) && !enum_exists($class)) {
                    throw new \InvalidArgumentException(\sprintf('The source index provider "%s" declares an invalid payload class.', $name));
                }
                if (isset($schema[$class])) {
                    throw new \InvalidArgumentException(\sprintf('The source index provider "%s" declares payload class "%s" more than once.', $name, $class));
                }
                if (isset($owners[$class]) && $owners[$class] !== $provider::class) {
                    throw new \InvalidArgumentException(\sprintf('Payload class "%s" is declared by multiple source index providers.', $class));
                }
                $schema[$class] = true;
                $owners[$class] = $provider::class;
            }
            $schemas[$name] = [...self::SHARED_CLASSES, ...array_keys($schema)];
        }

        $this->allowedClasses = $schemas;
    }

    public function encode(string $provider, SourceFactsInterface $facts): string
    {
        /** @var \SplObjectStorage<object, null> $visited */
        $visited = new \SplObjectStorage();
        $this->assertAllowed($facts, array_fill_keys($this->classes($provider), true), $visited, $provider);

        return base64_encode(serialize($facts));
    }

    public function decode(string $provider, string $payload): SourceFactsInterface
    {
        $serialized = base64_decode($payload, true);
        if (false === $serialized) {
            throw new \UnexpectedValueException('The source index payload is not valid base64.');
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \UnexpectedValueException($message);
        });
        try {
            $facts = unserialize($serialized, ['allowed_classes' => $this->classes($provider)]);
        } finally {
            restore_error_handler();
        }
        if (!$facts instanceof SourceFactsInterface) {
            throw new \UnexpectedValueException('The source index payload does not contain source facts.');
        }
        /** @var \SplObjectStorage<object, null> $visited */
        $visited = new \SplObjectStorage();
        $this->assertComplete($facts, $visited);

        return $facts;
    }

    /** @return list<class-string> */
    private function classes(string $provider): array
    {
        return $this->allowedClasses[$provider] ?? throw new \UnexpectedValueException(\sprintf('The source index provider "%s" has no payload schema.', $provider));
    }

    /**
     * @param array<class-string, true>       $allowedClasses
     * @param \SplObjectStorage<object, null> $visited
     */
    private function assertAllowed(mixed $value, array $allowedClasses, \SplObjectStorage $visited, string $provider): void
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->assertAllowed($item, $allowedClasses, $visited, $provider);
            }

            return;
        }
        if (!\is_object($value) || $visited->offsetExists($value)) {
            return;
        }
        if (!isset($allowedClasses[$value::class])) {
            throw new \UnexpectedValueException(\sprintf('The source index provider "%s" does not declare payload class "%s".', $provider, $value::class));
        }
        $visited->offsetSet($value, null);
        foreach ((array) $value as $item) {
            $this->assertAllowed($item, $allowedClasses, $visited, $provider);
        }
    }

    /** @param \SplObjectStorage<object, null> $visited */
    private function assertComplete(mixed $value, \SplObjectStorage $visited): void
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new \UnexpectedValueException('The source index payload contains an undeclared class.');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->assertComplete($item, $visited);
            }

            return;
        }
        if (!\is_object($value) || $visited->offsetExists($value)) {
            return;
        }
        $visited->offsetSet($value, null);
        foreach ((array) $value as $item) {
            $this->assertComplete($item, $visited);
        }
    }
}
