<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Project\Project;

final class SourceOverlayHealthRegistryTest extends TestCase
{
    public function testRecordsPartialDocumentsUntilAHealthyVersionArrives(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/src/Article.php';

        $registry->record($project, $uri, SourceParseHealth::Partial);
        self::assertTrue($registry->isDegraded($uri));

        $registry->record($project, $uri, SourceParseHealth::Healthy);
        self::assertFalse($registry->isDegraded($uri));
    }

    public function testHealthyStateClearsTheUriAfterProjectRemapping(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $uri = 'file:///workspace/src/Article.php';
        $registry->record(new Project('/workspace', 'file:///workspace'), $uri, SourceParseHealth::Partial);

        $registry->record(new Project('/workspace/src', 'file:///workspace/src'), $uri, SourceParseHealth::Healthy);

        self::assertFalse($registry->isDegraded($uri));
    }

    public function testClearForgetsTheUri(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $uri = 'file:///workspace/src/Article.php';
        $registry->record(new Project('/workspace', 'file:///workspace'), $uri, SourceParseHealth::Partial);

        $registry->clear($uri);

        self::assertFalse($registry->isDegraded($uri));
    }

    public function testProjectRemovalClearsOnlyItsDegradedDocuments(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $other = new Project('/other', 'file:///other');
        $registry->record($project, 'file:///workspace/src/Article.php', SourceParseHealth::Partial);
        $registry->record($other, 'file:///other/src/Article.php', SourceParseHealth::Partial);

        $registry->removeProject($project);

        self::assertFalse($registry->isDegraded('file:///workspace/src/Article.php'));
        self::assertTrue($registry->isDegraded('file:///other/src/Article.php'));
    }
}
