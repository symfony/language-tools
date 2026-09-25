<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Feature\CollectedDiagnostic;
use Symfony\Lsp\Feature\PartialParseDiagnosticFilter;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Project\Project;

final class PartialParseDiagnosticFilterTest extends TestCase
{
    public function testFiltersOnlyReproducedDeclarationDiagnosticsForTheDegradedPhpDocument(): void
    {
        $health = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/src/Listener.php';
        $health->record($project, $uri, SourceParseHealth::Partial);
        $filter = new PartialParseDiagnosticFilter($health);
        $document = new Document($uri, 'php', 2, '<?php final class Listener {');
        $diagnostics = array_map(static fn (string $code): CollectedDiagnostic => new CollectedDiagnostic('test', ['code' => $code]), [
            'console.unknown_argument',
            'console.unknown_option',
            'event.invalid_listener_method',
            'messenger.invalid_handler_signature',
            'service.not_found',
            'route.missing_parameters',
        ]);

        self::assertSame(
            ['service.not_found', 'route.missing_parameters'],
            array_map(static fn (CollectedDiagnostic $diagnostic): mixed => $diagnostic->diagnostic['code'] ?? null, $filter->filter($document, $diagnostics)),
        );
    }

    public function testKeepsDiagnosticsForHealthyAndOtherDocuments(): void
    {
        $health = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $degradedUri = 'file:///workspace/src/Listener.php';
        $health->record($project, $degradedUri, SourceParseHealth::Partial);
        $filter = new PartialParseDiagnosticFilter($health);
        $diagnostics = [new CollectedDiagnostic('test', ['code' => 'event.invalid_listener_method'])];

        self::assertSame($diagnostics, $filter->filter(
            new Document('file:///workspace/src/Other.php', 'php', 1, '<?php'),
            $diagnostics,
        ));
        self::assertSame($diagnostics, $filter->filter(
            new Document($degradedUri, 'twig', 1, ''),
            $diagnostics,
        ));
    }
}
