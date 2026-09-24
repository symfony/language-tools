<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class UnknownNameCodeActionBuilderTest extends TestCase
{
    public function testRanksNearbyNamesAndEditsOnlyTheReference(): void
    {
        $text = "😀 route('blog_index_paginate')";
        $converter = new PositionConverter();
        $range = $converter->toRange($text, (int) strpos($text, 'blog_index_paginate'), \strlen('blog_index_paginate'));
        $diagnostic = (new LspProtocolMapper())->diagnostic($range, 1, 'route.not_found', 'Unknown route.');
        $actions = (new UnknownNameCodeActionBuilder(new LspProtocolMapper()))->replacements(
            new Document('file:///workspace/src/Controller.php', 'php', 5, $text),
            $diagnostic,
            $range,
            'blog_index_paginate',
            ['unrelated', 'blog_index_paginatezz', 'blog_index_paginated', 'blog_index_paginate', 'blog_index_paginated'],
        );

        self::assertSame(['Replace with "blog_index_paginated"', 'Replace with "blog_index_paginatezz"'], array_column($actions, 'title'));
        self::assertSame([true, false], array_column($actions, 'isPreferred'));
        self::assertSame(['documentChanges' => [[
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php', 'version' => 5],
            'edits' => [['range' => $diagnostic['range'], 'newText' => 'blog_index_paginated']],
        ]]], $actions[0]['edit'] ?? null);
        self::assertSame(['documentChanges' => [[
            'textDocument' => ['uri' => 'file:///workspace/src/Controller.php', 'version' => 5],
            'edits' => [['range' => $diagnostic['range'], 'newText' => 'blog_index_paginatezz']],
        ]]], $actions[1]['edit'] ?? null);
    }

    public function testAmbiguousOrTwoEditSuggestionsAreNotPreferred(): void
    {
        $document = new Document('file:///workspace/src/Controller.php', 'php', 1, 'user_eit');
        $range = (new PositionConverter())->toRange('user_eit', 0, 8);
        $builder = new UnknownNameCodeActionBuilder(new LspProtocolMapper());
        $tied = $builder->replacements($document, [], $range, 'user_eit', ['user_exit', 'user_edit']);

        self::assertSame(['Replace with "user_edit"', 'Replace with "user_exit"'], array_column($tied, 'title'));
        self::assertSame([false, false], array_column($tied, 'isPreferred'));
        self::assertSame([false], array_column($builder->replacements($document, [], $range, 'user_eitt', ['user_edit']), 'isPreferred'));
    }

    public function testNumericNamesRemainStringsInEdits(): void
    {
        $document = new Document('file:///workspace/config/framework.yaml', 'yaml', 1, '403');
        $range = (new PositionConverter())->toRange('403', 0, 3);
        $action = (new UnknownNameCodeActionBuilder(new LspProtocolMapper()))->replacements($document, [], $range, '403', ['404'])[0];

        self::assertSame('Replace with "404"', $action['title']);
        self::assertSame(['documentChanges' => [[
            'textDocument' => ['uri' => $document->uri, 'version' => 1],
            'edits' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 3]], 'newText' => '404']],
        ]]], $action['edit'] ?? null);
    }

    public function testDoesNotSuggestDistantOrExistingNames(): void
    {
        $document = new Document('file:///workspace/src/Controller.php', 'php', 1, 'home');
        $range = (new PositionConverter())->toRange('home', 0, 4);
        $builder = new UnknownNameCodeActionBuilder(new LspProtocolMapper());

        self::assertSame([], $builder->replacements($document, [], $range, 'home', ['home', 'about', 'long_distant_name']));
        self::assertSame([], $builder->replacements($document, [], $range, '', ['home']));
    }
}
