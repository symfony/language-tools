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
        $diagnostics = [
            ['code' => 'event.invalid_listener_method'],
            ['code' => 'messenger.invalid_handler_signature'],
            ['code' => 'service.not_found'],
            ['code' => 'route.missing_parameters'],
        ];

        self::assertSame(
            ['service.not_found', 'route.missing_parameters'],
            array_column($filter->filter($document, $diagnostics), 'code'),
        );

        $collected = array_map(
            static fn (array $diagnostic): CollectedDiagnostic => new CollectedDiagnostic('provider', $diagnostic),
            $diagnostics,
        );
        self::assertSame(
            ['service.not_found', 'route.missing_parameters'],
            array_column(array_map(
                static fn (CollectedDiagnostic $diagnostic): array => $diagnostic->diagnostic,
                $filter->filterCollected($document, $collected),
            ), 'code'),
        );
    }

    public function testKeepsDiagnosticsForHealthyAndOtherDocuments(): void
    {
        $health = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $degradedUri = 'file:///workspace/src/Listener.php';
        $health->record($project, $degradedUri, SourceParseHealth::Partial);
        $filter = new PartialParseDiagnosticFilter($health);
        $diagnostics = [['code' => 'event.invalid_listener_method']];

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
