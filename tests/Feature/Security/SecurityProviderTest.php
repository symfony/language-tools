<?php

namespace Symfony\Lsp\Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Security\SecurityExtractor;
use Symfony\Lsp\Feature\Security\SecurityFirewall;
use Symfony\Lsp\Feature\Security\SecurityIndexRegistry;
use Symfony\Lsp\Feature\Security\SecurityProvider;
use Symfony\Lsp\Feature\Security\SecurityRole;
use Symfony\Lsp\Feature\Security\SecuritySourceIndexRegistry;
use Symfony\Lsp\Feature\Security\SecurityUserProvider;
use Symfony\Lsp\Feature\Security\SecurityVoter;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class SecurityProviderTest extends TestCase
{
    public function testExtractsOnlyRecognizedSecuritySymbols(): void
    {
        $converter = new PositionConverter();
        $extractor = new SecurityExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser())));
        $php = <<<'PHP'
<?php
namespace App;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
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
            array_map(static fn ($symbol): string => $symbol->name(), $extractor->extract('file:///workspace/src/AdminController.php', 'php', $php)->symbols()),
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
            array_map(static fn ($symbol): string => $symbol->name(), $extractor->extract('file:///workspace/config/packages/security.yaml', 'yaml', $yaml)->symbols()),
        );
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
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('ROLE_ADMIN')]
final class AdminController {}
PHP;
        $twigUri = 'file:///workspace/templates/admin.html.twig';
        $twig = "{% if is_granted('ROLE_ADMIN') %}{{ logout_path('missing') }}{% endif %}";
        $completionUri = 'file:///workspace/src/Completion.php';
        $completion = "<?php\nuse Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;\n#[IsGranted('ROLE_A')]\nfinal class Completion {}\n";
        $documents = new DocumentStore();
        foreach ([[$yamlUri, 'yaml', $yaml], [$phpUri, 'php', $php], [$twigUri, 'twig', $twig], [$completionUri, 'php', $completion]] as [$uri, $language, $text]) {
            $documents->open(new Document($uri, $language, 1, $text));
        }
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $extractor = new SecurityExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser())));
        $indexes = new SecurityIndexRegistry();
        $indexes->forProject($project)->replace(
            [new SecurityFirewall('main', 'users', true, false, true, ['App\\Security\\Authenticator'])],
            [new SecurityUserProvider('users', 'memory')],
            [new SecurityRole('ROLE_ADMIN', ['ROLE_USER']), new SecurityRole('ROLE_USER', [])],
            [new SecurityVoter('App\\Security\\PostVoter')],
            true,
        );
        $sourceIndexes = new SecuritySourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract($yamlUri, 'yaml', $yaml),
            $extractor->extract($phpUri, 'php', $php),
            $extractor->extract($twigUri, 'twig', $twig),
        );
        $provider = new SecurityProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $sourceIndexes, $extractor);

        $completionPosition = $converter->toPosition($completion, strpos($completion, "ROLE_A')") + \strlen('ROLE_A'));
        self::assertSame(['ROLE_ADMIN'], array_column($provider->complete($this->params($completionUri, $completionPosition)) ?? [], 'label'));
        $rolePosition = $converter->toPosition($php, (int) strpos($php, 'ROLE_ADMIN') + 2);
        $hover = $provider->hover($this->params($phpUri, $rolePosition));
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('App\\Security\\PostVoter', $hover['contents']['value']);
        $providerPosition = $converter->toPosition($yaml, (int) strpos($yaml, 'provider: users') + \strlen('provider: us'));
        self::assertSame([$yamlUri], array_column($provider->definition($this->params($yamlUri, $providerPosition)) ?? [], 'uri'));
        self::assertContains($twigUri, array_column($provider->references($this->params($phpUri, $rolePosition)) ?? [], 'uri'));
        self::assertSame(['security.unknown_provider'], array_column($provider->diagnostics(['textDocument' => ['uri' => $yamlUri]]) ?? [], 'code'));
        self::assertSame(['security.unknown_firewall'], array_column($provider->diagnostics(['textDocument' => ['uri' => $twigUri]]) ?? [], 'code'));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, Position $position): array
    {
        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
