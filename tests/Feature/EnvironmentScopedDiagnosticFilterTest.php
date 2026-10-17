<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\CollectedDiagnostic;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\EnvironmentScopedDiagnosticFilter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Tests\Support\EnvironmentScopes;

final class EnvironmentScopedDiagnosticFilterTest extends TestCase
{
    /** @param list<string> $expectedCodes */
    #[DataProvider('conventionalEnvironmentFileProvider')]
    public function testKeepsSelectedEnvironmentDiagnosticsOnlyInDocumentsThatEnvironmentLoads(string $uri, string $environment, array $expectedCodes, string $configPath = 'config'): void
    {
        $diagnostics = array_map(
            static fn (string $code): CollectedDiagnostic => new CollectedDiagnostic('stub', ['code' => $code]),
            ['service.not_found', 'parameter.not_found', 'messenger.unknown_transport', 'security.unknown_provider', 'env.unknown_processor', 'config.unknown_key', 'env.malformed_chain'],
        );

        self::assertSame(
            $expectedCodes,
            array_map(
                static fn (CollectedDiagnostic $diagnostic): mixed => $diagnostic->diagnostic['code'],
                $this->filter($environment, $configPath)->filter($uri, $diagnostics),
            ),
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: list<string>, 3?: string}> */
    public static function conventionalEnvironmentFileProvider(): iterable
    {
        $all = ['service.not_found', 'parameter.not_found', 'messenger.unknown_transport', 'security.unknown_provider', 'env.unknown_processor', 'config.unknown_key', 'env.malformed_chain'];
        $environmentAgnostic = ['config.unknown_key', 'env.malformed_chain'];

        yield 'inactive service file' => ['file:///workspace/config/services_test.yaml', 'dev', $environmentAgnostic];
        yield 'active service file' => ['file:///workspace/config/services_test.yaml', 'test', $all];
        yield 'active service file with yml extension' => ['file:///workspace/config/services_dev.yml', 'dev', $all];
        yield 'inactive package directory' => ['file:///workspace/config/packages/test/security.yaml', 'dev', $environmentAgnostic];
        yield 'active package directory' => ['file:///workspace/config/packages/test/security.yaml', 'test', $all];
        yield 'inactive route directory' => ['file:///workspace/config/routes/test/api.yaml', 'dev', $environmentAgnostic];
        yield 'package file lookalike' => ['file:///workspace/config/packages/test.yaml', 'dev', $all];
        yield 'service file outside config' => ['file:///workspace/src/services_test.yaml', 'dev', $all];
        yield 'document outside any project' => ['file:///elsewhere/config/services_test.yaml', 'dev', $all];
        yield 'inactive file in a custom configuration directory' => ['file:///workspace/etc/packages/test/security.yaml', 'dev', $environmentAgnostic, 'etc'];
        yield 'default configuration directory with a custom one' => ['file:///workspace/config/services_test.yaml', 'dev', $all, 'etc'];
    }

    public function testKeepsDiagnosticsWithoutAKnownCode(): void
    {
        $diagnostics = [
            new CollectedDiagnostic('stub', ['code' => 'unregistered.code']),
            new CollectedDiagnostic('stub', ['message' => 'No code at all.']),
        ];

        self::assertSame($diagnostics, $this->filter()->filter('file:///workspace/config/services_test.yaml', $diagnostics));
    }

    private function filter(string $environment = 'dev', string $configPath = 'config'): EnvironmentScopedDiagnosticFilter
    {
        $projects = new ProjectRegistry();
        $projects->replace([new Project('/workspace', 'file:///workspace', configPath: $configPath)]);

        return new EnvironmentScopedDiagnosticFilter($projects, EnvironmentScopes::resolver($environment), new DiagnosticCodeRegistry());
    }
}
