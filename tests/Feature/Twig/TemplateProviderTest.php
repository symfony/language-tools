<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Twig\TemplateCompletionHandler;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TemplateReferenceExtractor;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class TemplateProviderTest extends TestCase
{
    public function testCompletesAndLinksTemplateNames(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$this->render('article/show.html.twig');";
        [$completion, $navigation, $converter] = $this->providers($uri, 'php', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/sh') + \strlen('article/sh'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line(), 'character' => $position->character(),
        ]];

        self::assertSame(['article/show.html.twig'], array_column($completion->complete($params) ?? [], 'label'));
        self::assertSame(
            'file:///workspace/templates/article/show.html.twig',
            $navigation->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null,
        );
    }

    public function testNavigatesReferencesAndDiagnosesMissingTemplates(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = "{% extends 'article/show.html.twig' %}\n{% include 'missing.html.twig' %}";
        [, $navigation, $converter] = $this->providers($uri, 'twig', $text);
        $position = $converter->toPosition($text, strpos($text, 'article/show') + 1);
        $params = ['textDocument' => ['uri' => $uri], 'position' => [
            'line' => $position->line(), 'character' => $position->character(),
        ]];

        self::assertSame(
            ['file:///workspace/templates/article/show.html.twig'],
            array_column($navigation->definition($params) ?? [], 'uri'),
        );
        self::assertSame(
            ['template.not_found'],
            array_column($navigation->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'),
        );
    }

    /** @return array{TemplateCompletionHandler, TemplateNavigationProvider, PositionConverter} */
    private function providers(string $uri, string $languageId, string $text): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new TemplateIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, new TemplateDeclaration(
            'article/show.html.twig',
            'file:///workspace/templates/article/show.html.twig',
            new Range(new Position(0, 0), new Position(0, 0)),
        ));
        $converter = new PositionConverter();
        $extractor = new TemplateReferenceExtractor($converter);
        $resolver = new DocumentContextResolver($documents, $projects);

        return [
            new TemplateCompletionHandler($resolver, $converter, $indexes),
            new TemplateNavigationProvider($resolver, $documents, $projects, $converter, $extractor, $indexes),
            $converter,
        ];
    }
}
