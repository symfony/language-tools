<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeRefreshPlanner;
use Symfony\Lsp\Server\ContainerFactory;

final class SourceIndexProviderContractTest extends TestCase
{
    #[DataProvider('providerNameProvider')]
    public function testPersistedFactsSurviveTheirPayloadCodec(string $name): void
    {
        $providers = self::providers();
        $provider = $providers[$name];
        $codec = new SourceIndexPayloadCodec();
        $codec->validate($providers);
        $project = new Project('/workspace', 'file:///workspace');

        $provider->begin($project);
        $decoded = [];
        foreach (self::documents() as $document) {
            $facts = $provider->index($project, $document);
            if (null === $facts || $facts->isEmpty()) {
                continue;
            }
            $payload = $codec->encode($name, $facts);
            $restored = $codec->decode($name, $payload);
            self::assertEquals($facts, $restored);
            self::assertSame($payload, $codec->encode($name, $restored));
            $decoded[] = $restored;
        }
        $provider->finish($project);

        self::assertNotSame([], $decoded, \sprintf('No fixture source exercises the "%s" provider.', $name));

        $provider->begin($project);
        foreach ($decoded as $facts) {
            $provider->restore($project, $facts);
        }
        $provider->finish($project);
    }

    public function testEveryRuntimeRefreshDomainIsARegisteredProviderName(): void
    {
        self::assertSame([], array_diff(array_keys(RuntimeRefreshPlanner::DOMAIN_SECTIONS), array_keys(self::providers())));
    }

    /** @return iterable<string, array{string}> */
    public static function providerNameProvider(): iterable
    {
        foreach (array_keys(self::providers()) as $name) {
            yield $name => [$name];
        }
    }

    /** @return array<string, SourceIndexProviderInterface> */
    private static function providers(): array
    {
        $container = (new ContainerFactory())->create('test');
        $container->addCompilerPass(new PublicSourceIndexProviderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
        $container->compile();

        $providers = [];
        foreach (array_keys($container->findTaggedServiceIds('lsp.source_index_provider')) as $id) {
            $provider = $container->get($id);
            self::assertInstanceOf(SourceIndexProviderInterface::class, $provider);
            $providers[$provider->name()] = $provider;
        }
        ksort($providers);

        return $providers;
    }

    /** @return list<SourceDocument> */
    private static function documents(): array
    {
        return [
            new SourceDocument('file:///workspace/src/Controller/ArticleController.php', 'php', <<<'PHP'
                <?php
                namespace App\Controller;

                use App\Form\ArticleType;
                use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
                use Symfony\Component\DependencyInjection\Attribute\Autowire;
                use Symfony\Component\HttpFoundation\Response;
                use Symfony\Component\Routing\Attribute\Route;
                use Symfony\Component\Security\Http\Attribute\IsGranted;
                use Symfony\Component\Validator\Constraints as Assert;
                use Symfony\Contracts\Translation\TranslatorInterface;

                #[Route('/article', name: 'article_')]
                final class ArticleController extends AbstractController
                {
                    #[Assert\NotBlank(message: 'article.title.blank')]
                    public string $title = '';

                    public function __construct(
                        #[Autowire(service: 'app.article_repository')] private readonly object $articles,
                        private readonly TranslatorInterface $translator,
                    ) {
                    }

                    #[Route('/{slug}', name: 'show')]
                    #[IsGranted('ROLE_EDITOR')]
                    public function show(string $slug): Response
                    {
                        $this->createForm(ArticleType::class, null, ['label' => 'article.form']);
                        $this->translator->trans('article.title', [], 'messages');
                        $this->generateUrl('article_show', ['slug' => $slug]);

                        return $this->render('article/show.html.twig', ['dsn' => $_ENV['DATABASE_URL']]);
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/Entity/Article.php', 'php', <<<'PHP'
                <?php
                namespace App\Entity;

                use Doctrine\ORM\Mapping as ORM;

                #[ORM\Entity(repositoryClass: ArticleRepository::class)]
                class Article
                {
                    #[ORM\Id]
                    #[ORM\Column]
                    private ?int $id = null;

                    #[ORM\Column(length: 255)]
                    private string $title = '';
                }
                PHP),
            new SourceDocument('file:///workspace/src/Command/ReportCommand.php', 'php', <<<'PHP'
                <?php
                namespace App\Command;

                use Symfony\Component\Console\Attribute\AsCommand;
                use Symfony\Component\Console\Command\Command;
                use Symfony\Component\Console\Input\InputInterface;
                use Symfony\Component\Console\Output\OutputInterface;

                #[AsCommand(name: 'app:report')]
                final class ReportCommand extends Command
                {
                    protected function configure(): void
                    {
                        $this->addArgument('format');
                        $this->addOption('dry-run');
                    }

                    protected function execute(InputInterface $input, OutputInterface $output): int
                    {
                        $input->getArgument('format');

                        return 0;
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/MessageHandler/SendReportHandler.php', 'php', <<<'PHP'
                <?php
                namespace App\MessageHandler;

                use App\Message\SendReport;
                use Symfony\Component\Messenger\Attribute\AsMessageHandler;

                #[AsMessageHandler]
                final class SendReportHandler extends BaseHandler
                {
                    public function __invoke(SendReport $message): void
                    {
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/EventListener/AuditListener.php', 'php', <<<'PHP'
                <?php
                namespace App\EventListener;

                use App\Event\ArticlePublished;
                use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

                final class AuditListener
                {
                    #[AsEventListener(event: ArticlePublished::class)]
                    public function onArticlePublished(ArticlePublished $event): void
                    {
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/Twig/AppExtension.php', 'php', <<<'PHP'
                <?php
                namespace App\Twig;

                use Twig\Extension\AbstractExtension;
                use Twig\TwigFilter;
                use Twig\TwigFunction;

                final class AppExtension extends AbstractExtension
                {
                    public function getFunctions(): array
                    {
                        return [new TwigFunction('article_title', $this->title(...))];
                    }

                    public function getFilters(): array
                    {
                        return [new TwigFilter('shorten', $this->shorten(...))];
                    }

                    public function title(string $slug): string
                    {
                        return $slug;
                    }

                    public function shorten(string $text, int $length = 10): string
                    {
                        return substr($text, 0, $length);
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/Twig/Components/Search.php', 'php', <<<'PHP'
                <?php
                namespace App\Twig\Components;

                use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
                use Symfony\UX\LiveComponent\Attribute\LiveAction;

                #[AsLiveComponent('Search')]
                final class Search
                {
                    #[LiveAction]
                    public function submit(): void
                    {
                    }
                }
                PHP),
            new SourceDocument('file:///workspace/src/Model/ArticleStatus.php', 'php', <<<'PHP'
                <?php
                namespace App\Model;

                enum ArticleStatus: string
                {
                    case Draft = 'draft';
                    case Published = 'published';
                }
                PHP),
            new SourceDocument('file:///workspace/importmap.php', 'php', <<<'PHP'
                <?php

                return [
                    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                    'stimulus' => ['version' => '3.2.2'],
                ];
                PHP),
            new SourceDocument('file:///workspace/templates/article/show.html.twig', 'twig', <<<'TWIG'
                {% extends 'base.html.twig' %}

                {% block body %}
                    <link rel="stylesheet" href="{{ asset('styles/app.css') }}">
                    <a href="{{ path('article_show', {slug: 'first'}) }}">{{ 'article.title'|trans({}, 'messages') }}</a>
                    {% if is_granted('ROLE_EDITOR') %}
                        <div data-controller="search" data-search-url-value="/search">
                            <twig:Search query="first" />
                        </div>
                    {% endif %}
                    {{ article_title(slug: 'first')|shorten(length: 5) }}
                    {{ constant('App\\Model\\ArticleStatus::Draft') }}
                    {{ enum('App\\Model\\ArticleStatus').Published }}
                    {{ dsn|default(env('DATABASE_URL')) }}
                {% endblock %}
                TWIG),
            new SourceDocument('file:///workspace/config/services.yaml', 'yaml', <<<'YAML'
                parameters:
                    app.locale: 'en'
                    app.dsn: '%env(DATABASE_URL)%'

                services:
                    app.article_repository:
                        class: App\Repository\ArticleRepository
                        arguments: ['@doctrine', '%app.locale%']

                    App\EventListener\AuditListener:
                        tags:
                            - { name: kernel.event_listener, event: kernel.request, method: onArticlePublished }
                YAML),
            new SourceDocument('file:///workspace/config/services.xml', 'xml', <<<'XML'
                <?xml version="1.0" encoding="UTF-8" ?>
                <container xmlns="http://symfony.com/schema/dic/services">
                    <parameters>
                        <parameter key="app.xml_locale">en</parameter>
                    </parameters>
                    <services>
                        <service id="app.xml_service" class="App\Service\XmlService">
                            <argument type="service" id="app.article_repository"/>
                        </service>
                    </services>
                </container>
                XML),
            new SourceDocument('file:///workspace/config/routes.yaml', 'yaml', <<<'YAML'
                article_list:
                    path: /articles
                    controller: App\Controller\ArticleController::list
                YAML),
            new SourceDocument('file:///workspace/config/packages/security.yaml', 'yaml', <<<'YAML'
                security:
                    providers:
                        app_users:
                            entity: { class: App\Entity\User }
                    firewalls:
                        main:
                            provider: app_users
                    role_hierarchy:
                        ROLE_ADMIN: [ROLE_EDITOR]
                    access_control:
                        - { path: ^/admin, roles: ROLE_ADMIN }
                YAML),
            new SourceDocument('file:///workspace/config/packages/messenger.yaml', 'yaml', <<<'YAML'
                framework:
                    messenger:
                        transports:
                            async: '%env(MESSENGER_TRANSPORT_DSN)%'
                        routing:
                            App\Message\SendReport: async
                YAML),
            new SourceDocument('file:///workspace/translations/messages.en.yaml', 'yaml', <<<'YAML'
                article:
                    title: 'Article title'
                    form: 'Article form'
                YAML),
            new SourceDocument('file:///workspace/.env', 'dotenv', <<<'DOTENV'
                APP_ENV=dev
                DATABASE_URL="postgresql://app@127.0.0.1:5432/app"
                MESSENGER_TRANSPORT_DSN=doctrine://default
                DOTENV
            ),
            new SourceDocument('file:///workspace/assets/controllers/search_controller.js', 'javascript', <<<'JAVASCRIPT'
                import { Controller } from '@hotwired/stimulus';

                export default class extends Controller {
                    static targets = ['results'];
                    static values = { url: String };

                    search() {
                        this.resultsTarget.textContent = this.urlValue;
                    }
                }
                JAVASCRIPT),
        ];
    }
}

final class PublicSourceIndexProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('lsp.source_index_provider')) as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
    }
}
