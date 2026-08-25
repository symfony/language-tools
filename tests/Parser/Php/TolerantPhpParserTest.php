<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class TolerantPhpParserTest extends TestCase
{
    public function testExposesResolvedAttributesAndLiteralSourceOffsets(): void
    {
        $source = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\Routing\Attribute\Route as RoutingRoute;

            #[RoutingRoute(path: '/article', name: "article_list")]
            final class ArticleController extends AbstractController
            {
            }

            $routes->add('article_list', '/article');
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $attribute = $document->attributes()[0];
        $name = $attribute->argument('name')?->stringLiteral();

        self::assertSame('Symfony\Component\Routing\Attribute\Route', $attribute->name());
        self::assertInstanceOf(PhpStringLiteral::class, $name);
        self::assertSame('article_list', $name->value());
        self::assertSame('article_list', substr($source, $name->startOffset(), $name->endOffset() - $name->startOffset()));
        self::assertSame('$routes', $document->methodCalls()[0]->receiver());
        self::assertSame('add', $document->methodCalls()[0]->method());
        self::assertSame('article_list', $document->methodCalls()[0]->argument(0)?->stringLiteral()?->value());
        self::assertSame('ArticleController', $document->typeDeclarations()[0]->name());
        self::assertSame('Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController', $document->typeDeclarations()[0]->parentClassName());
        self::assertTrue($document->typeDeclarations()[0]->contains((int) strpos($source, 'ArticleController')));
        self::assertSame([], $document->diagnostics());
    }

    public function testDecodesEscapedStringLiteralsWithSourceOffsets(): void
    {
        $source = <<<'PHP'
            <?php
            #[Foo('App\\Mailer', "tab\tbrace\x7dend")]
            final class Service
            {
            }
            PHP;

        $attribute = (new TolerantPhpParser(new Parser()))->parse($source)->attributes()[0];
        $service = $attribute->argument(0)?->stringLiteral();
        $label = $attribute->argument(1)?->stringLiteral();

        self::assertInstanceOf(PhpStringLiteral::class, $service);
        self::assertSame('App\Mailer', $service->value());
        self::assertSame('App\\\\Mailer', substr($source, $service->startOffset(), $service->endOffset() - $service->startOffset()));
        self::assertInstanceOf(PhpStringLiteral::class, $label);
        self::assertSame("tab\tbrace}end", $label->value());
        self::assertSame('tab\\tbrace\\x7dend', substr($source, $label->startOffset(), $label->endOffset() - $label->startOffset()));
    }

    public function testExposesNamespaceImportsAndNameResolution(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Domain;

            use Vendor\Package\{First, Second as Alias};
            use Other\Single;
            use function Vendor\helper;
            use const Vendor\VALUE;
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);

        self::assertSame('App\Domain', $document->namespace());
        self::assertSame([
            'First' => 'Vendor\Package\First',
            'Alias' => 'Vendor\Package\Second',
            'Single' => 'Other\Single',
        ], $document->imports());
        self::assertSame('Vendor\Package\Second\Nested', $document->resolveName('Alias\Nested'));
        self::assertSame('App\Domain\Local', $document->resolveName('Local'));
        self::assertSame('App\Domain\Relative', $document->resolveName('namespace\Relative'));
        self::assertSame('Global\Name', $document->resolveName('\Global\Name'));
    }

    public function testExposesResolvedTypedParametersAndProperties(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            use Vendor\Package\{Handler, Other, Service};

            final class Example
            {
                public function __construct(private ?Service $service, Handler|Other $handler) {}

                protected Service $property;
                private Other $first = null, $second;
                private $untyped;
                private static $alsoUntyped;
                public int $scalar;

                /** @var Service $documentedOnly */
                private $documentedOnly;

                public function handle((Handler&Other)|Service $combined, int $count): void {}
            }
            PHP;

        $variables = (new TolerantPhpParser(new Parser()))->parse($source)->typedVariables();

        self::assertSame(['service', 'handler', 'property', 'first', 'second', 'combined'], array_map(static fn ($variable): string => $variable->name(), $variables));
        self::assertSame([
            ['Vendor\Package\Service'],
            ['Vendor\Package\Handler', 'Vendor\Package\Other'],
            ['Vendor\Package\Service'],
            ['Vendor\Package\Other'],
            ['Vendor\Package\Other'],
            ['Vendor\Package\Handler', 'Vendor\Package\Other', 'Vendor\Package\Service'],
        ], array_map(static fn ($variable): array => $variable->types(), $variables));
    }

    public function testExposesObjectCreationCallablesAndMethodDeclarations(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use App\Twig\Runtime\AppRuntime as Runtime;
            use Twig\TwigFunction as FunctionDefinition;

            final class AppExtension
            {
                private $untyped;

                public function __construct(private Runtime $runtime) {}

                public function getFunctions(): array
                {
                    return [
                        new FunctionDefinition('array_name', [Runtime::class, 'fromArray']),
                        new FunctionDefinition(name: 'self_name', callable: [self::class, 'ownMethod']),
                        new FunctionDefinition('this_name', $this->ownMethod(...)),
                        new FunctionDefinition('property_name', $this->runtime->fromProperty(...)),
                        new FunctionDefinition('untyped_name', $this->untyped->render(...)),
                        new FunctionDefinition('static_name', Runtime::fromStatic(...)),
                        new FunctionDefinition('empty_name', []),
                    ];
                }

                /** Formats the value. */
                public function ownMethod(string $value = 'a  b'): string { return $value; }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $creations = $document->objectCreations();

        self::assertSame(['array_name', 'self_name', 'this_name', 'property_name', 'untyped_name', 'static_name', 'empty_name'], array_map(static fn ($creation): ?string => $creation->argument('name')?->stringLiteral()?->value() ?? $creation->argument(0)?->stringLiteral()?->value(), $creations));
        self::assertSame(array_fill(0, 7, 'getFunctions'), array_map(static fn ($creation): ?string => $creation->enclosingMethod(), $creations));
        self::assertSame([
            ['App\Twig\Runtime\AppRuntime', 'fromArray'],
            ['App\Twig\AppExtension', 'ownMethod'],
            ['App\Twig\AppExtension', 'ownMethod'],
            [null, null],
            [null, null],
            ['App\Twig\Runtime\AppRuntime', 'fromStatic'],
            [null, null],
        ], array_map(static fn ($creation): array => [
            ($creation->argument('callable') ?? $creation->argument(1))?->callable()?->className(),
            ($creation->argument('callable') ?? $creation->argument(1))?->callable()?->method(),
        ], $creations));
        $method = array_values(array_filter($document->methodDeclarations(), static fn ($method): bool => 'ownMethod' === $method->name()))[0];
        self::assertSame('App\Twig\AppExtension', $method->className());
        self::assertSame("public function ownMethod(string \$value = 'a  b'): string", $method->signature());
        self::assertSame('Formats the value.', $method->description());
    }

    public function testExposesMethodAttributesAndCallableShape(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\Attribute\AsTwigFunction as FunctionAttribute;
            use Twig\Environment as TwigEnvironment;

            final class AppExtension
            {
                #[FunctionAttribute('format')]
                public function format(TwigEnvironment $environment, string $value, mixed ...$options): string
                {
                    return $value;
                }

                public function union(TwigEnvironment|null $environment): void {}

                #[FunctionAttribute('hidden')]
                private function hidden(): void {}

                public function parameter(#[FunctionAttribute('invalid')] string $value): void {}
            }
            PHP;

        $methods = (new TolerantPhpParser(new Parser()))->parse($source)->methodDeclarations();

        self::assertSame('Twig\Attribute\AsTwigFunction', $methods[0]->attributes()[0]->name());
        self::assertSame('format', $methods[0]->attributes()[0]->argument(0)?->stringLiteral()?->value());
        self::assertSame('Twig\Environment', $methods[0]->firstParameterType());
        self::assertFalse($methods[0]->isFirstParameterVariadic());
        self::assertTrue($methods[0]->isVariadic());
        self::assertTrue($methods[0]->isPublic());
        self::assertStringStartsWith('public function format', $methods[0]->signature());
        self::assertNull($methods[1]->firstParameterType());
        self::assertFalse($methods[2]->isPublic());
        self::assertSame([], $methods[3]->attributes());
    }

    public function testKeepsImportsFromIncompleteGroupedSyntax(): void
    {
        $document = (new TolerantPhpParser(new Parser()))->parse("<?php\n// café\nnamespace App;\nuse Vendor\\Package\\{First, Second as Alias");

        self::assertSame([
            'First' => 'Vendor\Package\First',
            'Alias' => 'Vendor\Package\Second',
        ], $document->imports());
    }

    public function testRejectsInterpolatedStringsAsLiteralsAndReportsSyntaxDiagnostics(): void
    {
        $source = <<<'PHP'
            <?php
            #[Route(name: "article_$action")]
            final class ArticleController
            {
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);

        self::assertNull($document->attributes()[0]->argument('name')?->stringLiteral());
        self::assertCount(1, $document->diagnostics());
        self::assertSame("'}' expected.", $document->diagnostics()[0]->message());
    }
}
