<?php

namespace Symfony\Lsp\Tests\Feature;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerBus;
use Symfony\Lsp\Feature\Messenger\MessengerCodeActionProvider;
use Symfony\Lsp\Feature\Messenger\MessengerIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerSourceFacts;
use Symfony\Lsp\Feature\Messenger\MessengerSourceIndexRegistry;
use Symfony\Lsp\Feature\Messenger\MessengerSourceSymbol;
use Symfony\Lsp\Feature\Messenger\MessengerSymbolKind;
use Symfony\Lsp\Feature\Messenger\MessengerTransport;
use Symfony\Lsp\Feature\Metadata\ConstraintOptionReference;
use Symfony\Lsp\Feature\Metadata\FormOptionReference;
use Symfony\Lsp\Feature\Metadata\FormType;
use Symfony\Lsp\Feature\Metadata\MetadataCodeActionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSourceFacts;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\ValidationConstraint;
use Symfony\Lsp\Feature\Security\SecurityCodeActionProvider;
use Symfony\Lsp\Feature\Security\SecurityFirewall;
use Symfony\Lsp\Feature\Security\SecurityIndexRegistry;
use Symfony\Lsp\Feature\Security\SecuritySourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexRegistry;
use Symfony\Lsp\Feature\Security\SecuritySourceSymbol;
use Symfony\Lsp\Feature\Security\SecuritySymbolKind;
use Symfony\Lsp\Feature\Security\SecurityUserProviderDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentCodeActionProvider;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigComponentNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentPhpExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentReference;
use Symfony\Lsp\Feature\Twig\TwigComponentResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigComponentTemplateExtractor;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\EnvironmentScopes;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\ProviderRequests;

final class UnknownNameSemanticActionsTest extends TestCase
{
    public function testMessengerSuggestionsUseTheReferencedKind(): void
    {
        $uri = 'file:///workspace/config/packages/messenger.yaml';
        $text = "bus: command.bu\ntransport: asyn\n";
        [$document, $project, $converter, $protocol, $requests] = $this->context($uri, 'yaml', $text);
        $busRange = $converter->toRange($text, (int) strpos($text, 'command.bu'), \strlen('command.bu'));
        $transportRange = $converter->toRange($text, (int) strpos($text, 'asyn'), \strlen('asyn'));
        $indexes = new MessengerIndexRegistry();
        $indexes->forProject($project)->replace([new MessengerBus('command.bus', true)], [new MessengerTransport('async', false)], [], [], true);
        $sources = new MessengerSourceIndexRegistry();
        $sources->forProject($project)->replace(new MessengerSourceFacts($uri, [
            new MessengerSourceSymbol(MessengerSymbolKind::Bus, 'command.bu', $uri, $busRange, false),
            new MessengerSourceSymbol(MessengerSymbolKind::Transport, 'asyn', $uri, $transportRange, false),
        ]));
        $diagnostics = [
            $protocol->diagnostic($busRange, 1, 'messenger.unknown_bus', 'Unknown bus.'),
            $protocol->diagnostic($transportRange, 1, 'messenger.unknown_transport', 'Unknown transport.'),
        ];
        $provider = new MessengerCodeActionProvider($indexes, $sources, EnvironmentScopes::resolver(), ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol));

        $actions = $provider->actions($requests->codeAction($uri, $diagnostics));

        self::assertSame(['Replace with "command.bus"', 'Replace with "async"'], array_column($actions, 'title'));
        $this->assertEdits($actions, $diagnostics, ['command.bus', 'async'], $uri);
        self::assertSame([], $provider->actions($requests->codeAction($uri, [$protocol->diagnostic($busRange, 1, 'messenger.unknown_transport', 'Wrong kind.')])));
        $indexes->forProject($project)->replace([new MessengerBus('command.bus', true)], [new MessengerTransport('async', false)], [], [], false);
        self::assertSame([], $provider->actions($requests->codeAction($uri, $diagnostics)));
    }

    public function testSecuritySuggestionsIncludeSourceDeclarationsAndStayInKind(): void
    {
        $uri = 'file:///workspace/config/packages/security.yaml';
        $text = "firewall: main_are\nprovider: userz\n";
        [, $project, $converter, $protocol, $requests] = $this->context($uri, 'yaml', $text);
        $firewallRange = $converter->toRange($text, (int) strpos($text, 'main_are'), \strlen('main_are'));
        $providerRange = $converter->toRange($text, (int) strpos($text, 'userz'), \strlen('userz'));
        $indexes = new SecurityIndexRegistry();
        $indexes->forProject($project)->replace([new SecurityFirewall('main_area', 'users', true, false, true, [])], [new SecurityUserProviderDeclaration('users', 'memory')], [], [], true);
        $sources = new SecuritySourceIndexRegistry();
        $sources->forProject($project)->replace(new SecuritySourceFacts($uri, [
            new SecuritySourceSymbol(SecuritySymbolKind::Firewall, 'main_are', $uri, $firewallRange, false),
            new SecuritySourceSymbol(SecuritySymbolKind::Provider, 'userz', $uri, $providerRange, false),
        ]));
        $diagnostics = [
            $protocol->diagnostic($firewallRange, 1, 'security.unknown_firewall', 'Unknown firewall.'),
            $protocol->diagnostic($providerRange, 1, 'security.unknown_provider', 'Unknown provider.'),
        ];
        $actions = (new SecurityCodeActionProvider($indexes, $sources, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, $diagnostics));

        self::assertSame(['Replace with "main_area"', 'Replace with "users"'], array_column($actions, 'title'));
        $this->assertEdits($actions, $diagnostics, ['main_area', 'users'], $uri);
    }

    public function testOptionsUseOnlyTheirOwningTypeOrConstraint(): void
    {
        $uri = 'file:///workspace/src/Form.php';
        $text = "<?php ['requird' => true, 'messag' => 'invalid'];";
        [, $project, $converter, $protocol, $requests] = $this->context($uri, 'php', $text);
        $formRange = $converter->toRange($text, (int) strpos($text, 'requird'), \strlen('requird'));
        $constraintRange = $converter->toRange($text, (int) strpos($text, 'messag'), \strlen('messag'));
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace([new FormType('App\\Form\\EventType', 'event', ['required'], [])], [new ValidationConstraint('NotBlank', 'App\\NotBlank', ['message'])], true, true);
        $sources = new MetadataSourceIndexRegistry();
        $sources->forProject($project)->replace(new MetadataSourceFacts($uri, [], formOptions: [new FormOptionReference('App\\Form\\EventType', 'requird', $formRange)], constraintOptions: [new ConstraintOptionReference('NotBlank', 'messag', $constraintRange)]));
        $diagnostics = [
            $protocol->diagnostic($formRange, 1, 'form.unknown_option', 'Unknown option.'),
            $protocol->diagnostic($constraintRange, 1, 'validation.unknown_constraint_option', 'Unknown constraint option.'),
        ];
        $actions = (new MetadataCodeActionProvider($indexes, $sources, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, $diagnostics));

        self::assertSame(['Replace with "required"', 'Replace with "message"'], array_column($actions, 'title'));
        $this->assertEdits($actions, $diagnostics, ['required', 'message'], $uri);
        $indexes->forProject($project)->replace([new FormType('App\\Form\\EventType', 'event', ['required'], [])], [new ValidationConstraint('NotBlank', 'App\\NotBlank', ['message'])], false, false);
        self::assertSame([], (new MetadataCodeActionProvider($indexes, $sources, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, $diagnostics)));
    }

    public function testTwigComponentSuggestionsUseEffectiveNames(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = '<twig:UserCrad />';
        [, $project, $converter, $protocol, $requests] = $this->context($uri, 'twig', $text);
        $range = $converter->toRange($text, (int) strpos($text, 'UserCrad'), \strlen('UserCrad'));
        $indexes = new TwigComponentIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, true, ['UserCard'], 'components');
        $indexes->forProject($project)->replace(new TwigComponentSourceFacts($uri, [], [new TwigComponentReference('UserCrad', $uri, $range)]));
        $templates = new TemplateIndexRegistry(new DependencyInjectionSourceIndexRegistry());
        $templates->forProject($project)->replaceRuntime(true);
        $names = new TwigComponentNameResolver(new TemplateNameResolver(ProjectPaths::resolver()));
        $extractor = new TwigComponentExtractor(
            new TolerantPhpParser(new Parser()),
            new TwigComponentPhpExtractor($converter, $names),
            new TwigComponentTemplateExtractor($converter, $names, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser(), new TwigDirectiveLocator()), new TwigCallArgumentResolver()),
        );
        $componentResolver = new TwigComponentResolver(new PositionedSourceSymbolResolver($converter), $indexes, $templates, $extractor);
        $diagnostic = $protocol->diagnostic($range, 1, 'twig_component.not_found', 'Unknown component.');
        $actions = (new TwigComponentCodeActionProvider($indexes, $templates, $componentResolver, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, [$diagnostic]));

        self::assertSame(['Replace with "UserCard"'], array_column($actions, 'title'));
        $this->assertEdits($actions, [$diagnostic], ['UserCard'], $uri, false);
        $indexes->forProject($project)->replaceRuntime(true, true, [], 'components');
        $templates->forProject($project)->replaceRuntime(true, new TemplateDeclaration('components/UserCard.html.twig', 'file:///workspace/templates/components/UserCard.html.twig', $range));
        $anonymousActions = (new TwigComponentCodeActionProvider($indexes, $templates, $componentResolver, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, [$diagnostic]));
        $this->assertEdits($anonymousActions, [$diagnostic], ['UserCard'], $uri, false);
        $indexes->forProject($project)->replaceRuntime(false, true, ['UserCard'], 'components');
        self::assertSame([], (new TwigComponentCodeActionProvider($indexes, $templates, $componentResolver, ProjectPaths::resolver(), new UnknownNameCodeActionBuilder($protocol)))->actions($requests->codeAction($uri, [$diagnostic])));
    }

    /** @return array{Document, Project, PositionConverter, LspProtocolMapper, ProviderRequests} */
    private function context(string $uri, string $language, string $text): array
    {
        $document = new Document($uri, $language, 4, $text);
        $documents = new DocumentStore();
        $documents->open($document);
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);

        return [
            $document,
            $project,
            new PositionConverter(),
            new LspProtocolMapper(),
            new ProviderRequests($documents, $projects),
        ];
    }

    /**
     * @param list<array<array-key, mixed>> $actions
     * @param list<array<array-key, mixed>> $diagnostics
     * @param list<string>                  $names
     */
    private function assertEdits(array $actions, array $diagnostics, array $names, string $uri, bool $preferred = true): void
    {
        foreach ($names as $index => $name) {
            self::assertSame([
                'title' => \sprintf('Replace with "%s"', $name),
                'kind' => 'quickfix',
                'diagnostics' => [$diagnostics[$index]],
                'isPreferred' => $preferred,
                'edit' => ['documentChanges' => [[
                    'textDocument' => ['uri' => $uri, 'version' => 4],
                    'edits' => [['range' => $diagnostics[$index]['range'], 'newText' => $name]],
                ]]],
            ], $actions[$index] ?? null);
        }
    }
}
