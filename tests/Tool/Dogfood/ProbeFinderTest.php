<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\Probe;
use Symfony\Lsp\Tools\Dogfood\ProbeFinder;

final class ProbeFinderTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->project);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testFindsTheAlphabeticallyFirstMatchDeterministically(): void
    {
        $this->write('src/Controller/ZebraController.php', "<?php\n\$this->redirectToRoute('zebra_route');\n");
        $this->write('src/Controller/AlphaController.php', "<?php\n\$this->redirectToRoute('alpha_route');\n");

        $probes = $this->probes(new ProbeFinder(), 'route.php');

        self::assertCount(1, $probes);
        self::assertSame('alpha_route', $probes[0]->value);
        self::assertStringEndsWith('src/Controller/AlphaController.php', $probes[0]->path);
    }

    public function testKeepsABoundedNumberOfProbesPerCategoryFromDistinctFiles(): void
    {
        $this->write('src/A.php', "<?php\n\$this->redirectToRoute('a_route');\n\$this->redirectToRoute('extra_route');\n");
        $this->write('src/B.php', "<?php\n\$this->redirectToRoute('b_route');\n");
        $this->write('src/C.php', "<?php\n\$this->redirectToRoute('c_route');\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 2), 'route.php');

        self::assertSame(['a_route', 'b_route'], array_map(static fn (Probe $probe): string => $probe->value, $probes));
    }

    public function testFindsRouteProbesOnlyOnSupportedPhpReceivers(): void
    {
        $this->write('src/AController.php', "<?php\n\$this->generateUrl('helper_route');\n");
        $this->write('src/BService.php', "<?php\n\$this->router->generate('router_route');\n");
        $this->write('src/CService.php', "<?php\n\$urlGenerator->generate('generator_route');\n");
        $this->write('src/DService.php', "<?php\n\$tokens->generate('unrelated_token');\ngenerate('unrelated_call');\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 4), 'route.php');

        self::assertSame(
            ['helper_route', 'router_route', 'generator_route'],
            array_map(static fn (Probe $probe): string => $probe->value, $probes),
        );
    }

    public function testExcludesDependencyAndGeneratedDirectories(): void
    {
        foreach (['vendor', 'node_modules', 'var', '.git'] as $directory) {
            $this->write('src/'.$directory.'/Excluded.php', "<?php\n\$this->redirectToRoute('excluded_route');\n");
        }
        $this->write('src/Kept.php', "<?php\n\$this->redirectToRoute('kept_route');\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 10), 'route.php');

        self::assertCount(1, $probes);
        self::assertSame('kept_route', $probes[0]->value);
    }

    public function testScansConfiguredRootsInsteadOfTheDefaults(): void
    {
        $this->write('lib/Controller.php', "<?php\n\$this->redirectToRoute('lib_route');\n");

        self::assertSame([], $this->probes(new ProbeFinder(), 'route.php'));
        $probes = $this->probes(new ProbeFinder(['lib']), 'route.php');
        self::assertCount(1, $probes);
        self::assertSame('lib_route', $probes[0]->value);
    }

    public function testFindsCustomTwigCallables(): void
    {
        $this->write('src/AppExtension.php', "<?php\nuse Twig\\TwigFilter;\nuse Twig\\TwigFunction;\nfinal class AppExtension\n{\n    public function getFunctions(): array\n    {\n        return [\n            new TwigFunction('unused_widget', fn () => ''),\n            new TwigFunction('app_widget', fn () => ''),\n        ];\n    }\n    public function getFilters(): array\n    {\n        return [new TwigFilter('app_short', fn () => '')];\n    }\n}\n");
        $this->write('templates/home.html.twig', "{{ app_widget() }}\n{{ 'text'|app_short }}\n");

        $finder = new ProbeFinder();

        self::assertSame('app_widget', $this->probes($finder, 'twig.function')[0]->value);
        self::assertSame('app_short', $this->probes($finder, 'twig.filter')[0]->value);
        $functionDeclaration = $this->probes($finder, 'twig.function.php')[0];
        $filterDeclaration = $this->probes($finder, 'twig.filter.php')[0];
        self::assertSame('app_widget', $functionDeclaration->value);
        self::assertSame('app_short', $filterDeclaration->value);
        $this->assertPositionInsideValue($functionDeclaration);
        $this->assertPositionInsideValue($filterDeclaration);
    }

    public function testFindsTwigConstantsAndEnums(): void
    {
        $this->write('src/Status.php', "<?php\nnamespace App;\nenum Status { case Published; }\nfinal class Options { public const FORMAT = 'html'; }\n");
        $this->write('templates/home.html.twig', "{{ constant('App\\\\Options::FORMAT') }}\n{{ enum('App\\\\Status').Published }}\n");

        $finder = new ProbeFinder();
        $constant = $this->probes($finder, 'twig.constant')[0];
        $enum = $this->probes($finder, 'twig.enum')[0];

        self::assertSame('FORMAT', $constant->value);
        self::assertSame('Published', $enum->value);
        $this->assertPositionInsideValue($constant);
        $this->assertPositionInsideValue($enum);
    }

    public function testFindsTwigFirewallNamesOutsideMethodCalls(): void
    {
        $this->write('templates/layout.html.twig', "{{ security.logout_path('ignored') }}\n{{ logout_path('main') }}\n");

        $probes = $this->probes(new ProbeFinder(), 'security.firewall.twig');

        self::assertCount(1, $probes);
        self::assertSame('main', $probes[0]->value);
        $this->assertPositionInsideValue($probes[0]);
    }

    public function testFindsTwigImportmapEntrypointsOutsideMethodCalls(): void
    {
        $this->write('templates/layout.html.twig', "{{ app.importmap('ignored') }}\n{{ importmap('app') }}\n");

        $probes = $this->probes(new ProbeFinder(), 'importmap.twig');

        self::assertCount(1, $probes);
        self::assertSame('app', $probes[0]->value);
        $this->assertPositionInsideValue($probes[0]);
    }

    public function testFindsFormPropertiesOnlyWithAStaticDataClass(): void
    {
        $this->write('src/Form/ArticleType.php', <<<'PHP'
            <?php
            final class ArticleType
            {
                public function buildForm(FormBuilderInterface $builder): void
                {
                    // $builder->add('commented', TextType::class);
                    $builder
                        ->add('ignored', TextType::class, ['mapped' => false])
                        ->add('title', TextType::class)
                    ;
                }

                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefaults(['data_class' => Article::class]);
                }
            }
            PHP);
        $this->write('src/Form/DynamicType.php', <<<'PHP'
            <?php
            $builder->add('dynamic', TextType::class);
            $resolver->setDefaults(['data_class' => $class]);
            PHP);
        $this->write('src/Form/UnmappedType.php', "<?php\n\$builder->add('unmapped', TextType::class);\n");

        $probes = $this->probes(new ProbeFinder(probesPerCategory: 10), 'form.property.php');

        self::assertCount(1, $probes);
        self::assertSame('title', $probes[0]->value);
        $this->assertPositionInsideValue($probes[0]);
    }

    public function testIgnoresLargeFormTypesWithoutADataClass(): void
    {
        $this->write('src/Form/UnmappedType.php', "<?php\n".str_repeat("\$builder->add('unmapped', TextType::class);\n", 1000));

        self::assertSame([], $this->probes(new ProbeFinder(), 'form.property.php'));
    }

    public function testFindsAttributedTwigCallables(): void
    {
        $this->write('src/AppExtension.php', "<?php\nuse Twig\\Attribute\\AsTwigFunction;\nfinal class AppExtension\n{\n    #[AsTwigFunction('app_widget')]\n    public function widget() {}\n    #[\\Twig\\Attribute\\AsTwigFilter(name: 'app_short')]\n    public function shorten() {}\n}\n");
        $this->write('templates/home.html.twig', "{{ app_widget() }}\n{{ 'text'|app_short }}\n");

        $finder = new ProbeFinder();

        self::assertSame('app_widget', $this->probes($finder, 'twig.function')[0]->value);
        self::assertSame('app_short', $this->probes($finder, 'twig.filter')[0]->value);
        $functionDeclaration = $this->probes($finder, 'twig.function.php')[0];
        $filterDeclaration = $this->probes($finder, 'twig.filter.php')[0];
        self::assertSame('app_widget', $functionDeclaration->value);
        self::assertSame('app_short', $filterDeclaration->value);
        $this->assertPositionInsideValue($functionDeclaration);
        $this->assertPositionInsideValue($filterDeclaration);
    }

    public function testFindsConsoleArgumentAndOptionNames(): void
    {
        $this->write('src/Command/ImportCommand.php', <<<'PHP'
            <?php
            final class ImportCommand extends Command
            {
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $input->getArgument('source');
                    $input->getOption('format');
                    $options->getOption('unrelated');

                    return 0;
                }
            }
            PHP);

        $finder = new ProbeFinder();
        $argument = $this->probes($finder, 'console.argument.php')[0];
        $option = $this->probes($finder, 'console.option.php')[0];

        self::assertSame('source', $argument->value);
        self::assertSame('format', $option->value);
        $this->assertPositionInsideValue($argument);
        $this->assertPositionInsideValue($option);
    }

    public function testSkipsMatchesOnCommentedLines(): void
    {
        $this->write('config/packages/assets.yaml', <<<'YAML'
            framework:
                assets:
                    # json_manifest_path: '%kernel.project_dir%/public/build/manifest.json'
                    base_path: '%kernel.custom_dir%/uploads'
            YAML);
        $this->write('src/Controller.php', "<?php\n// \$this->redirectToRoute('commented_route');\n\$this->redirectToRoute('active_route');\n");
        $this->write('templates/page.twig', "{# {{ path('commented_route') }} #}\n{{ path('active_twig_route') }}\n");

        self::assertSame('kernel.custom_dir', $this->probes(new ProbeFinder(), 'parameter.yaml')[0]->value);
        self::assertSame('active_route', $this->probes(new ProbeFinder(), 'route.php')[0]->value);
        self::assertSame('active_twig_route', $this->probes(new ProbeFinder(), 'route.twig')[0]->value);
    }

    public function testReturnsNoProbeWhenEveryMatchIsCommented(): void
    {
        $this->write('config/services.yaml', "parameters:\n    # commented: '%kernel.project_dir%'\n");

        self::assertSame([], $this->probes(new ProbeFinder(), 'parameter.yaml'));
    }

    public function testKeepsMatchesAfterInlineFragmentMarkers(): void
    {
        $this->write('config/packages/framework.yaml', "framework:\n    router:\n        default_uri: 'https://example.com/a#%app.fragment%'\n");

        self::assertSame('app.fragment', $this->probes(new ProbeFinder(), 'parameter.yaml')[0]->value);
    }

    public function testFindsYamlResourceImports(): void
    {
        $this->write('config/routes.yaml', <<<'YAML'
            controllers:
                resource:
                    path: ../src/Controller/
                    namespace: App\Controller
                type: attribute
            imports:
                - { resource: packages/framework.yaml }
            YAML);

        $probes = $this->probes(new ProbeFinder(), 'import.yaml');

        self::assertCount(1, $probes);
        self::assertSame('packages/framework.yaml', $probes[0]->value);
    }

    public function testReportsThePositionInsideTheMatchedValue(): void
    {
        $this->write('src/Controller.php', "<?php\n\$this->redirectToRoute('abcd');\n");

        $probe = $this->probes(new ProbeFinder(), 'route.php')[0];

        self::assertSame(1, $probe->line);
        self::assertSame(26, $probe->character);
        self::assertSame('abcd', $probe->value);
    }

    /**
     * @return list<Probe>
     */
    private function probes(ProbeFinder $finder, string $category): array
    {
        return array_values(array_filter(
            $finder->find($this->project),
            static fn (Probe $probe): bool => $probe->category === $category,
        ));
    }

    private function assertPositionInsideValue(Probe $probe): void
    {
        $line = explode("\n", $probe->contents)[$probe->line];

        self::assertSame($probe->value, substr($line, $probe->character - intdiv(\strlen($probe->value), 2), \strlen($probe->value)));
    }

    private function write(string $relativePath, string $contents): void
    {
        (new Filesystem())->dumpFile(Path::join($this->project, $relativePath), $contents);
    }
}
