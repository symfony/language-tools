<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Runtime\RuntimeMetadataException;

final class RuntimeMetadataExceptionTest extends TestCase
{
    public function testListsFailedSections(): void
    {
        $error = new RuntimeMetadataException(['routes', 'twig'], [[
            'section' => 'twig',
            'chain' => [
                ['class' => \RuntimeException::class, 'message' => 'Twig failed.', 'origin' => 'src/TwigExtension.php:24', 'frames' => ['App\\TwigExtension->load (src/TwigExtension.php:20)']],
                ['class' => \LogicException::class, 'message' => 'Missing service.', 'frames' => []],
            ],
        ]]);

        self::assertSame('The project bridge could not load runtime metadata: routes, twig.', $error->getMessage());
        self::assertSame([
            'Runtime section "twig": RuntimeException at src/TwigExtension.php:24: Twig failed.',
            '  at App\\TwigExtension->load (src/TwigExtension.php:20)',
            'Caused by: LogicException: Missing service.',
        ], $error->detailLines());
    }

    public function testReportsAKernelBootFailureOnce(): void
    {
        $error = new RuntimeMetadataException(['runtime', 'routes', 'container'], [[
            'section' => 'runtime',
            'chain' => [
                ['class' => \Error::class, 'message' => 'Undefined constant "PROJECT_ROOT"', 'origin' => 'vendor/distribution/Kernel.php:61', 'frames' => []],
            ],
        ]]);

        self::assertSame('The project bridge could not boot the application kernel.', $error->getMessage());
        self::assertSame([
            'Kernel boot: Error at vendor/distribution/Kernel.php:61: Undefined constant "PROJECT_ROOT"',
        ], $error->detailLines());
    }
}
