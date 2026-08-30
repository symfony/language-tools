<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class RoutePhpReceiver
{
    /**
     * @param list<PhpTypeDeclaration> $types
     */
    public static function resolve(string $source, int $offset, array $types): ?self
    {
        $source = rtrim($source);

        if (preg_match('/\$this\s*$/', $source)) {
            foreach ($types as $type) {
                if ($type->contains($offset)) {
                    return new self($type->name);
                }
            }

            return null;
        }

        if (!preg_match('/(?:\$this->|\$)(\w+)\s*$/', $source, $receiver)) {
            return null;
        }

        if (!preg_match(
            '/(?:RouterInterface|UrlGeneratorInterface)\s+\$'.preg_quote($receiver[1], '/').'\b/s',
            $source,
        )) {
            return null;
        }

        return new self(null);
    }

    private function __construct(
        private readonly ?string $controllerClass,
    ) {
    }

    public function controllerClass(): ?string
    {
        return $this->controllerClass;
    }
}
