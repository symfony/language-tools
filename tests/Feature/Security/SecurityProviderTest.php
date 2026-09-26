<?php

namespace Symfony\Lsp\Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Security\SecurityCompletionProvider;
use Symfony\Lsp\Feature\Security\SecurityDiagnosticProvider;
use Symfony\Lsp\Feature\Security\SecurityExtractor;
use Symfony\Lsp\Feature\Security\SecurityRelationshipProvider;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class SecurityProviderTest extends TestCase
{
    public function testExtractsOnlyRecognizedSecuritySymbols(): void
    {
        $extractor = $this->extractor();
        $php = <<<'PHP'
<?php
namespace App;
use Symfony\{Bundle\FrameworkBundle\Controller\AbstractController, Component\Security\Core\Authorization\AuthorizationCheckerInterface};
final class AdminController extends AbstractController
{
    public function __construct(private AuthorizationCheckerInterface $security) {}
    public function index(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->security->isGranted('ROLE_USER');
        $other->isGranted('ROLE_IGNORED');
    }
}
PHP;
        self::assertSame(
            ['ROLE_ADMIN', 'ROLE_USER'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/src/AdminController.php', 'php', $php))->symbols),
        );

        $yaml = <<<'YAML'
security:
  providers:
    users:
      memory: ~
  firewalls:
    main:
      provider: users
  role_hierarchy:
    ROLE_ADMIN: [ROLE_USER]
  access_control:
    - path: ^/admin
      roles: ROLE_EDITOR
YAML;
        self::assertSame(
            ['users', 'main', 'users', 'ROLE_ADMIN', 'ROLE_USER', 'ROLE_EDITOR'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/config/packages/security.yaml', 'yaml', $yaml))->symbols),
        );
    }

    public function testExtractsStaticTwigAuthorizationArgumentsConservatively(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {# {{ is_granted('ROLE_COMMENTED') }} #}
            {% if is_granted('ROLE_ADMIN') %}{{ logout_path('main') }}{% endif %}
            {{ logout_url('api') }}
            {{ is_granted('ROLE_SUBJECT', post) }}
            {{ is_granted(role) }}
            {{ is_granted('POST_EDIT', post) }}
            {{ is_granted(attribute: 'ROLE_NAMED') }}{{ logout_path(key: 'named') }}
            {{ user.is_granted('ROLE_METHOD') }}
            {% set snippet = 'is_granted(\'ROLE_STRING\') and logout_path(\'string\')' %}
            {% verbatim %}{{ is_granted('ROLE_VERBATIM') }}{{ logout_path('verbatim') }}{% endverbatim %}
            TWIG));

        self::assertSame(
            [['role', 'ROLE_ADMIN'], ['firewall', 'main'], ['firewall', 'api'], ['role', 'ROLE_SUBJECT'], ['role', 'ROLE_NAMED'], ['firewall', 'named']],
            array_map(static fn ($symbol): array => [$symbol->kind->value, $symbol->name], $facts->symbols),
        );
    }

    public function testScopesTypedSecurityReceiversToTheirOwningMethods(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

            final class Controller
            {
                public function allowed(AuthorizationCheckerInterface $security): void
                {
                    $security->isGranted('ROLE_ALLOWED');
                }

                public function unrelated(object $security): void
                {
                    $security->isGranted('ROLE_UNRELATED');
                }
            }
            PHP;

        self::assertSame(
            ['ROLE_ALLOWED'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', $text))->symbols),
        );

        $completion = str_replace("isGranted('ROLE_UNRELATED');", "isGranted('ROLE_U", $text);
        self::assertNull($extractor->completionContext('php', $completion, \strlen($completion)));
    }

    public function testScopesTypedSecurityPropertiesToTheirOwningClasses(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

            final class AdminController
            {
                public function __construct(private AuthorizationCheckerInterface $security) {}

                public function index(): void
                {
                    $this->security->isGranted('ROLE_ADMIN');
                }
            }

            final class UnrelatedController
            {
                public function __construct(private object $security) {}

                public function index(): void
                {
                    $this->security->isGranted('ROLE_UNRELATED');
                }
            }
            PHP;

        self::assertSame(
            ['ROLE_ADMIN'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', $text))->symbols),
        );

        $completion = str_replace("isGranted('ROLE_UNRELATED');", "isGranted('ROLE_U", $text);
        self::assertNull($extractor->completionContext('php', $completion, \strlen($completion)));
    }

    public function testScopesControllerCallsToAbstractControllerSubclasses(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class AdminController extends AbstractController
            {
                public function index(): void
                {
                    $this->denyAccessUnlessGranted('ROLE_ADMIN');
                }
            }

            final class UnrelatedController
            {
                public function index(): void
                {
                    $this->denyAccessUnlessGranted('ROLE_UNRELATED');
                }
            }
            PHP;

        self::assertSame(
            ['ROLE_ADMIN'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', $text))->symbols),
        );

        $completion = str_replace("denyAccessUnlessGranted('ROLE_UNRELATED');", "denyAccessUnlessGranted('ROLE_U", $text);
        self::assertNull($extractor->completionContext('php', $completion, \strlen($completion)));
    }

    public function testResolvesIsGrantedAttributesWithoutShortNameFalsePositives(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Security\Http\Attribute\IsGranted as RequiresRole;

            #[RequiresRole(attribute: 'ROLE_ALIAS')]
            final class AliasedController {}

            #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_FULL')]
            final class FullyQualifiedController {}

            #[IsGranted('ROLE_UNRELATED')]
            final class UnrelatedController {}
            PHP;

        self::assertSame(
            ['ROLE_ALIAS', 'ROLE_FULL'],
            array_map(static fn ($symbol): string => $symbol->name, $extractor->extract(new SourceDocument('file:///workspace/src/Controller.php', 'php', $text))->symbols),
        );

        $aliasedCompletion = str_replace("ROLE_ALIAS')]", 'ROLE_A', $text);
        self::assertSame('ROLE_A', $extractor->completionContext('php', $aliasedCompletion, strpos($aliasedCompletion, 'ROLE_A') + \strlen('ROLE_A'))?->prefix);

        $fullyQualifiedCompletion = str_replace("ROLE_FULL')]", 'ROLE_F', $text);
        self::assertSame('ROLE_F', $extractor->completionContext('php', $fullyQualifiedCompletion, strpos($fullyQualifiedCompletion, 'ROLE_F') + \strlen('ROLE_F'))?->prefix);

        $unrelatedCompletion = str_replace("ROLE_UNRELATED')]", 'ROLE_U', $text);
        self::assertNull($extractor->completionContext('php', $unrelatedCompletion, strrpos($unrelatedCompletion, 'ROLE_U') + \strlen('ROLE_U')));
    }

    public function testIgnoresCommentedPhpSecurityConstructs(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
            use Symfony\Component\Security\Http\Attribute\IsGranted;

            final class AdminController extends AbstractController
            {
                public function __construct(private AuthorizationCheckerInterface $security) {}

                public function index(): void
                {
                    // #[IsGranted('ROLE_ATTRIBUTE')]
                    // $this->denyAccessUnlessGranted('ROLE_CONTROLLER');
                    // $this->security->isGranted('ROLE_CHECKER');
                }
            }
            PHP;

        self::assertSame([], $extractor->extract(new SourceDocument('file:///workspace/src/AdminController.php', 'php', $text))->symbols);
    }

    #[DataProvider('rejectedPhpCompletionProvider')]
    public function testOffersNoPhpCompletionWhereIndexingReadsNoSymbol(string $body): void
    {
        $extractor = $this->extractor();
        $text = <<<PHP
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
            use Symfony\Component\Security\Http\Attribute\IsGranted;

            final class DemoController extends AbstractController
            {
                public function index(AuthorizationCheckerInterface \$security, object \$other): void
                {
                    {$body}
                }
            }
            PHP;
        $cursor = strpos($text, '|');
        self::assertIsInt($cursor);

        self::assertNull($extractor->completionContext('php', str_replace('|', '', $text), $cursor));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPhpCompletionProvider(): iterable
    {
        yield 'static call' => ['AuthorizationCheckerInterface::isGranted(\'ROLE_A|'];
        yield 'untyped receiver' => ['$other->isGranted(\'ROLE_A|'];
        yield 'array literal' => ['$security->isGranted([\'ROLE_A|'];
        yield 'second argument' => ['$security->isGranted(\'ROLE_ADMIN\', \'ROLE_A|'];
        yield 'controller call on another receiver' => ['$other->denyAccessUnlessGranted(\'ROLE_A|'];
        yield 'logout path on an unrelated receiver' => ['$other->getLogoutPath(\'ma|'];
    }

    public function testCompletesRolesInGroupedIsGrantedAttributes(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Security\Http\Attribute\IsGranted;

            #[\Deprecated, IsGranted('ROLE_A
            PHP;

        self::assertSame('ROLE_A', $extractor->completionContext('php', $text, \strlen($text))?->prefix);
    }

    public function testOffersNoSecurityCompletionsInsidePhpComments(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    // $this->denyAccessUnlessGranted('ROLE_A
                }
            }
            PHP;

        self::assertNull($extractor->completionContext('php', $text, strpos($text, 'ROLE_A') + \strlen('ROLE_A')));
    }

    public function testOffersTwigSecurityCompletionsOnlyInsideDirectives(): void
    {
        $extractor = $this->extractor();
        $directive = "{% if is_granted('ROLE_A";
        $markup = "<p>Call is_granted('ROLE_A";

        self::assertNotNull($extractor->completionContext('twig', $directive, \strlen($directive)));
        self::assertNull($extractor->completionContext('twig', $markup, \strlen($markup)));
    }

    public function testCompletesHoversNavigatesReferencesAndDiagnoses(): void
    {
        $yamlUri = 'file:///workspace/config/packages/security.yaml';
        $yaml = <<<'YAML'
security:
  providers:
    users:
      memory: ~
  firewalls:
    main:
      provider: users
    broken:
      provider: missing_provider
  role_hierarchy:
    ROLE_ADMIN: ROLE_USER
YAML;
        $phpUri = 'file:///workspace/src/AdminController.php';
        $php = <<<'PHP'
<?php
namespace App;
use Symfony\Component\Security\Http\Attribute\{IsGranted};
#[IsGranted('ROLE_ADMIN')]
final class AdminController {}
PHP;
        $twigUri = 'file:///workspace/templates/admin.html.twig';
        $twig = "{## Use is_granted('ROLE_DOCUMENTED') and logout_path('documented') in examples. #}\n{% if is_granted('ROLE_ADMIN') %}{{ logout_path('missing') }}{% endif %}";
        $completionUri = 'file:///workspace/src/Completion.php';
        $completion = "<?php\nuse Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;\n#[IsGranted('ROLE_A')]\nfinal class Completion {}\n";
        $kit = (new ProjectTestKit())
            ->open($yamlUri, $yaml)
            ->open($phpUri, $php)
            ->open($twigUri, $twig)
            ->open($completionUri, $completion)
            ->index([$yamlUri => $yaml, $phpUri => $php, $twigUri => $twig])
            ->runtime('security', [
                'firewalls' => [['name' => 'main', 'provider' => 'users', 'enabled' => true, 'stateless' => false, 'lazy' => true, 'authenticators' => ['App\\Security\\Authenticator']]],
                'providers' => [['name' => 'users', 'type' => 'memory']],
                'roles' => [['name' => 'ROLE_ADMIN', 'inheritedRoles' => ['ROLE_USER']], ['name' => 'ROLE_USER']],
                'voters' => [['class' => 'App\\Security\\PostVoter']],
                'complete' => true,
            ])
        ;
        $relationshipProvider = $kit->get(SecurityRelationshipProvider::class);
        $diagnosticProvider = $kit->get(SecurityDiagnosticProvider::class);

        self::assertSame(['ROLE_ADMIN'], $kit->labels($kit->get(SecurityCompletionProvider::class)->complete($kit->positioned($kit->after($completionUri, 'ROLE_A')))));
        foreach ([
            "{{ is_granted(attribute: 'ROLE_A" => ['ROLE_ADMIN'],
            "{{ logout_path(key: 'ma" => ['main'],
            "{{ user.is_granted('ROLE_A" => [],
            "{{ value|is_granted('ROLE_A" => [],
            "{{ links.logout_url('ma" => [],
        ] as $index => $case) {
            $twigCompletionUri = 'file:///workspace/templates/completion-'.$index.'.html.twig';
            $kit->open($twigCompletionUri, $index);
            self::assertSame($case, $kit->labels($kit->get(SecurityCompletionProvider::class)->complete($kit->positioned($kit->offset($twigCompletionUri, \strlen($index))))), $index);
        }
        $role = $kit->inside($phpUri, 'ROLE_ADMIN');
        self::assertStringContainsString('App\\Security\\PostVoter', $kit->hoverText($relationshipProvider->hover($kit->positioned($role))));
        self::assertSame([$yamlUri], $kit->targets($relationshipProvider->definition($kit->positioned($kit->after($yamlUri, 'provider: us')))));
        self::assertContains($twigUri, $kit->targets($relationshipProvider->references($kit->references($role))));
        self::assertSame(['security.unknown_provider'], $kit->codes($diagnosticProvider->diagnostics($kit->document($yamlUri))));
        self::assertSame(['security.unknown_firewall'], $kit->codes($diagnosticProvider->diagnostics($kit->document($twigUri))));
    }

    public function testPreservesDashedProviderAndFirewallNames(): void
    {
        $yamlUri = 'file:///workspace/config/packages/security.yaml';
        $yaml = <<<'YAML'
            security:
              providers:
                in-memory:
                  memory: ~
              firewalls:
                main-area:
                  provider: in-memory
            YAML;
        $twigUri = 'file:///workspace/templates/logout.html.twig';
        $twig = "{{ logout_path('main-area') }}";
        $kit = (new ProjectTestKit())
            ->open($yamlUri, $yaml)
            ->open($twigUri, $twig)
            ->index()
            ->runtime('security', ['complete' => true])
        ;
        $relationshipProvider = $kit->get(SecurityRelationshipProvider::class);
        $diagnosticProvider = $kit->get(SecurityDiagnosticProvider::class);
        $yamlFacts = $kit->get(SecuritySourceIndexRegistry::class)->forProject($kit->project())->factsForUri($yamlUri);

        self::assertNotNull($yamlFacts);
        self::assertSame(['in-memory', 'main-area', 'in-memory'], array_map(static fn ($symbol): string => $symbol->name, $yamlFacts->symbols));

        $provider = $kit->after($yamlUri, 'provider: in-me');
        self::assertSame([$yamlUri], $kit->targets($relationshipProvider->definition($kit->positioned($provider))));
        self::assertSame([$yamlUri, $yamlUri], $kit->targets($relationshipProvider->references($kit->references($provider))));

        $firewall = $kit->inside($twigUri, 'main-area');
        self::assertSame([$yamlUri], $kit->targets($relationshipProvider->definition($kit->positioned($firewall))));
        self::assertSame([$yamlUri, $twigUri], $kit->targets($relationshipProvider->references($kit->references($firewall))));
        self::assertSame([$twigUri], $kit->targets($relationshipProvider->references($kit->references($firewall, includeDeclaration: false))));

        self::assertSame([], $diagnosticProvider->diagnostics($kit->document($yamlUri)));
        self::assertSame([], $diagnosticProvider->diagnostics($kit->document($twigUri)));
    }

    public function testResolvesSymbolAtRangeEnd(): void
    {
        $uri = 'file:///workspace/config/packages/security.yaml';
        $text = <<<'YAML'
            security:
              providers:
                users:
                  memory: ~
              firewalls:
                main:
                  provider: users
            YAML;
        $kit = (new ProjectTestKit())->open($uri, $text)->index();

        self::assertSame([$uri], $kit->targets($kit->get(SecurityRelationshipProvider::class)->definition($kit->positioned($kit->after($uri, 'provider: users')))));
    }

    private function extractor(): SecurityExtractor
    {
        return (new ProjectTestKit())->get(SecurityExtractor::class);
    }
}
