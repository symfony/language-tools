<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectAnalysisSettings;

final class AnalysisSettingsRegistryTest extends TestCase
{
    public function testKeepsASwitchedEnvironmentAndKernelAcrossUnrelatedSettingsRefreshes(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $settings = new AnalysisSettingsRegistry();
        $settings->configureProject($project, new ProjectAnalysisSettings(environment: 'dev', kernel: 'App\\Kernel', debug: true));

        $settings->setEnvironment($project, 'test');
        $settings->setKernel($project, 'Admin\\Kernel');
        $settings->configureProject($project, new ProjectAnalysisSettings(environment: 'dev', kernel: 'App\\Kernel', debug: false));

        self::assertSame('test', $settings->forProject($project)->environment);
        self::assertSame('Admin\\Kernel', $settings->forProject($project)->kernel);
        self::assertFalse($settings->forProject($project)->debug);
    }

    public function testLetsANewlyConfiguredValueReplaceASwitchedOne(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $settings = new AnalysisSettingsRegistry();
        $settings->configureProject($project, new ProjectAnalysisSettings(environment: 'dev', kernel: 'App\\Kernel'));

        $settings->setEnvironment($project, 'test');
        $settings->setKernel($project, 'Admin\\Kernel');
        $settings->configureProject($project, new ProjectAnalysisSettings(environment: 'prod', kernel: 'Api\\Kernel'));

        self::assertSame('prod', $settings->forProject($project)->environment);
        self::assertSame('Api\\Kernel', $settings->forProject($project)->kernel);
    }

    public function testFallsBackToTheWorkspaceKernelWhenTheSwitchedKernelIsCleared(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $settings = new AnalysisSettingsRegistry();
        $settings->configureWorkspace(new ProjectAnalysisSettings(kernel: 'Workspace\\Kernel'));
        $settings->configureProject($project, new ProjectAnalysisSettings(kernel: 'App\\Kernel'));

        $settings->setKernel($project, null);
        $settings->configureProject($project, new ProjectAnalysisSettings(kernel: 'App\\Kernel'));

        self::assertSame('Workspace\\Kernel', $settings->forProject($project)->kernel);
    }
}
