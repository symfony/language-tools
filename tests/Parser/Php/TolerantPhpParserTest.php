<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpConstantKind;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;
use Symfony\Lsp\Parser\Php\PhpTypeKind;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class TolerantPhpParserTest extends TestCase
{
    public function testExposesResolvedAttributesAndLiteralSourceOffsets(): void
    {
        $source = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\Routing\Attribute\Route as RoutingRoute;

            #[RoutingRoute(/* path marker */ path: '/article', name: "article_list")]
            final class ArticleController extends AbstractController
            {
            }

            $routes->add('article_list', '/article');
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $attribute = $document->attributes[0];
        $path = $attribute->argument('path');
        $name = $attribute->argument('name')?->stringLiteral;

        self::assertSame('Symfony\Component\Routing\Attribute\Route', $attribute->name);
        self::assertSame('path', substr($source, (int) $path?->nameStartOffset, (int) $path?->nameEndOffset - (int) $path?->nameStartOffset));
        self::assertInstanceOf(PhpStringLiteral::class, $name);
        self::assertSame('article_list', $name->value);
        self::assertSame('article_list', substr($source, $name->startOffset, $name->endOffset - $name->startOffset));
        self::assertSame('$routes', $document->methodCalls[0]->receiver);
        self::assertSame('add', $document->methodCalls[0]->method);
        self::assertSame('article_list', $document->methodCalls[0]->positionalArgument(0)?->stringLiteral?->value);
        self::assertSame('ArticleController', $document->typeDeclarations[0]->name);
        self::assertSame('Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController', $document->typeDeclarations[0]->parentClassName);
        self::assertTrue($document->typeDeclarations[0]->contains((int) strpos($source, 'ArticleController')));
        self::assertSame([], $document->diagnostics);
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

        $attribute = (new TolerantPhpParser(new Parser()))->parse($source)->attributes[0];
        $service = $attribute->positionalArgument(0)?->stringLiteral;
        $label = $attribute->positionalArgument(1)?->stringLiteral;

        self::assertInstanceOf(PhpStringLiteral::class, $service);
        self::assertSame('App\Mailer', $service->value);
        self::assertSame('App\\\\Mailer', substr($source, $service->startOffset, $service->endOffset - $service->startOffset));
        self::assertInstanceOf(PhpStringLiteral::class, $label);
        self::assertSame("tab\tbrace}end", $label->value);
        self::assertSame('tab\\tbrace\\x7dend', substr($source, $label->startOffset, $label->endOffset - $label->startOffset));
    }

    public function testExposesAttributePlacementAndArgumentRanges(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            // café
            #[TypeAttribute(name: 'service')]
            final class Service
            {
                #[PropertyAttribute]
                public string $name;

                #[MethodAttribute(option: Dependency::class)]
                public function run(#[ParameterAttribute] string $value): void {}
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $attributes = $document->attributes;
        [$type, $property, $method, $parameter] = $attributes;
        $typeTarget = $type->targets[0];
        $propertyTarget = $property->targets[0];
        $methodTarget = $method->targets[0];
        $name = $type->argument('name');
        $option = $method->argument('option');
        self::assertInstanceOf(PhpArgument::class, $name);
        self::assertInstanceOf(PhpArgument::class, $option);
        $nameExpressionStart = $name->expressionStartOffset;
        $nameExpressionEnd = $name->expressionEndOffset;
        $optionExpressionStart = $option->expressionStartOffset;
        $optionExpressionEnd = $option->expressionEndOffset;
        self::assertIsInt($nameExpressionStart);
        self::assertIsInt($nameExpressionEnd);
        self::assertIsInt($optionExpressionStart);
        self::assertIsInt($optionExpressionEnd);

        self::assertSame("#[TypeAttribute(name: 'service')]", substr($source, $type->startOffset, $type->endOffset - $type->startOffset));
        self::assertSame('TypeAttribute', substr($source, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset));
        self::assertSame(PhpAttributeTargetKind::Type, $typeTarget->kind);
        self::assertSame('App\Service', $typeTarget->className);
        self::assertNull($typeTarget->memberName);
        self::assertSame('Service', substr($source, $typeTarget->nameStartOffset, $typeTarget->nameEndOffset - $typeTarget->nameStartOffset));
        self::assertSame(PhpAttributeTargetKind::Property, $propertyTarget->kind);
        self::assertSame('name', $propertyTarget->memberName);
        self::assertSame(PhpAttributeTargetKind::Method, $methodTarget->kind);
        self::assertSame('run', $methodTarget->memberName);
        self::assertSame([], $parameter->targets);
        self::assertSame("name: 'service'", substr($source, $name->startOffset, $name->endOffset - $name->startOffset));
        self::assertSame("'service'", substr($source, $nameExpressionStart, $nameExpressionEnd - $nameExpressionStart));
        self::assertSame('Dependency::class', substr($source, $optionExpressionStart, $optionExpressionEnd - $optionExpressionStart));
        self::assertSame(['App\Dependency'], array_map(static fn ($reference): string => $reference->className, $document->classReferences));
        self::assertSame('Dependency', substr($source, $document->classReferences[0]->startOffset, $document->classReferences[0]->endOffset - $document->classReferences[0]->startOffset));
    }

    public function testTargetsPromotedParameterAttributesAsProperties(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            use Doctrine\ORM\Mapping as ORM;

            final class Product
            {
                public function __construct(
                    #[ORM\Column]
                    private string $name,
                    #[ORM\Column]
                    string $unpromoted,
                ) {
                }
            }
            PHP;

        $attributes = (new TolerantPhpParser(new Parser()))->parse($source)->attributes;

        self::assertSame(PhpAttributeTargetKind::Property, $attributes[0]->targets[0]->kind);
        self::assertSame('App\Product', $attributes[0]->targets[0]->className);
        self::assertSame('name', $attributes[0]->targets[0]->memberName);
        self::assertSame([], $attributes[1]->targets);
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

        $variables = (new TolerantPhpParser(new Parser()))->parse($source)->typedVariables;

        self::assertSame(['service', 'handler', 'property', 'first', 'second', 'scalar', 'combined', 'count'], array_map(static fn ($variable): string => $variable->name, $variables));
        self::assertSame([
            ['Vendor\Package\Service'],
            ['Vendor\Package\Handler', 'Vendor\Package\Other'],
            ['Vendor\Package\Service'],
            ['Vendor\Package\Other'],
            ['Vendor\Package\Other'],
            ['int'],
            ['Vendor\Package\Handler', 'Vendor\Package\Other', 'Vendor\Package\Service'],
            ['int'],
        ], array_map(static fn ($variable): array => $variable->types, $variables));
    }

    public function testExposesScopedTypedReceiversAndCalls(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            use Vendor\Bus;

            final class Handler
            {
                public function __construct(private Bus $bus) {}

                public function first(Bus $local): void
                {
                    $local->dispatch('first');
                    $this->bus->dispatch('property');
                }

                public function second(Bus $local): void
                {
                    $local->dispatch('second');
                    $other->dispatch('ignored');
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        [$promoted, $firstParameter, $secondParameter] = $document->typedVariables;
        [$firstCall, $propertyCall, $secondCall, $otherCall] = $document->methodCalls;
        $firstArgument = $firstCall->positionalArgument(0);
        self::assertInstanceOf(PhpArgument::class, $firstArgument);
        $firstArgumentStart = $firstArgument->expressionStartOffset;
        $firstArgumentEnd = $firstArgument->expressionEndOffset;
        self::assertIsInt($firstArgumentStart);
        self::assertIsInt($firstArgumentEnd);

        self::assertSame([PhpTypedVariableKind::PromotedProperty, PhpTypedVariableKind::Parameter, PhpTypedVariableKind::Parameter], array_map(static fn ($variable): PhpTypedVariableKind => $variable->kind, $document->typedVariables));
        self::assertSame(array_fill(0, 3, 'App\Handler'), array_map(static fn ($variable): ?string => $variable->className, $document->typedVariables));
        self::assertSame(['__construct', 'first', 'second'], array_map(static fn ($variable): ?string => $variable->methodName, $document->typedVariables));
        self::assertNotSame($firstParameter->scopeStartOffset, $secondParameter->scopeStartOffset);
        self::assertSame('bus', substr($source, $promoted->nameStartOffset, $promoted->nameEndOffset - $promoted->nameStartOffset));
        self::assertSame(PhpMethodReceiverKind::Variable, $firstCall->receiverContext->kind);
        self::assertSame('local', $firstCall->receiverContext->name);
        self::assertSame($firstParameter->scopeStartOffset, $firstCall->scopeStartOffset);
        self::assertSame('first', $firstCall->enclosingMethod);
        self::assertSame('App\Handler', $firstCall->className);
        self::assertSame(PhpMethodReceiverKind::ThisProperty, $propertyCall->receiverContext->kind);
        self::assertSame('bus', $propertyCall->receiverContext->name);
        self::assertSame($secondParameter->scopeStartOffset, $secondCall->scopeStartOffset);
        self::assertSame(PhpMethodReceiverKind::Variable, $otherCall->receiverContext->kind);
        self::assertSame("'first'", substr($source, $firstArgumentStart, $firstArgumentEnd - $firstArgumentStart));
        self::assertSame('$local->dispatch(\'first\')', substr($source, $firstCall->startOffset, $firstCall->endOffset - $firstCall->startOffset));
    }

    public function testExposesPropertyDeclarationsWithoutInitializerValues(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Model;

            use Vendor\Identity\Identifier;
            use Vendor\Profile as UserProfile;

            final class Customer
            {
                /**
                 * Stores customer identities.
                 *
                 * @var Identifier
                 */
                private ?Identifier $primary = null, $secondary = null;
                protected UserProfile|null $profile;
                public string $token = 'secret';
                private $untyped;
            }
            PHP;

        $properties = (new TolerantPhpParser(new Parser()))->parse($source)->propertyDeclarations;

        self::assertSame(['primary', 'secondary', 'profile', 'token', 'untyped'], array_map(static fn ($property): string => $property->name, $properties));
        self::assertSame(array_fill(0, 5, 'App\Model\Customer'), array_map(static fn ($property): string => $property->className, $properties));
        self::assertSame([
            'private ?Identifier $primary',
            'private ?Identifier $secondary',
            'protected UserProfile|null $profile',
            'public string $token',
            'private $untyped',
        ], array_map(static fn ($property): string => $property->signature, $properties));
        self::assertSame([
            ['Vendor\Identity\Identifier'],
            ['Vendor\Identity\Identifier'],
            ['Vendor\Profile'],
            ['string'],
            [],
        ], array_map(static fn ($property): array => $property->types, $properties));
        self::assertSame(['private', 'private', 'protected', 'public', 'private'], array_map(static fn ($property): string => $property->visibility, $properties));
        self::assertSame([false, false, false, true, false], array_map(static fn ($property): bool => $property->isPublic(), $properties));
        self::assertSame(['Stores customer identities.', 'Stores customer identities.', null, null, null], array_map(static fn ($property): ?string => $property->description, $properties));
        self::assertSame(array_fill(0, 5, false), array_map(static fn ($property): bool => $property->promoted, $properties));
        foreach ($properties as $property) {
            self::assertSame($property->name, substr($source, $property->nameStartOffset, $property->nameEndOffset - $property->nameStartOffset));
        }
    }

    public function testExposesPromotedPropertyDeclarations(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Model;

            use Vendor\Identity\Identifier;
            use Vendor\Service;

            final class Customer
            {
                public function __construct(
                    public Service $service = new Service(),
                    protected readonly ?Identifier $identifier = null,
                    private $token = 'secret',
                    string $ordinary = '',
                ) {
                }
            }
            PHP;

        $properties = (new TolerantPhpParser(new Parser()))->parse($source)->propertyDeclarations;

        self::assertSame(['service', 'identifier', 'token'], array_map(static fn ($property): string => $property->name, $properties));
        self::assertSame([
            'public Service $service',
            'protected readonly ?Identifier $identifier',
            'private $token',
        ], array_map(static fn ($property): string => $property->signature, $properties));
        self::assertSame([
            ['Vendor\Service'],
            ['Vendor\Identity\Identifier'],
            [],
        ], array_map(static fn ($property): array => $property->types, $properties));
        self::assertSame(['public', 'protected', 'private'], array_map(static fn ($property): string => $property->visibility, $properties));
        self::assertSame([true, false, false], array_map(static fn ($property): bool => $property->isPublic(), $properties));
        self::assertSame(array_fill(0, 3, true), array_map(static fn ($property): bool => $property->promoted, $properties));
        foreach ($properties as $property) {
            self::assertSame('App\Model\Customer', $property->className);
            self::assertSame($property->name, substr($source, $property->nameStartOffset, $property->nameEndOffset - $property->nameStartOffset));
        }
    }

    public function testKeepsPropertyDeclarationsFromIncompleteSource(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            use Vendor\Identity\Identifier;

            final class Draft
            {
                public function __construct(private Identifier $identifier =
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $property = $document->propertyDeclarations[0];

        self::assertSame('identifier', $property->name);
        self::assertSame('private Identifier $identifier', $property->signature);
        self::assertSame(['Vendor\Identity\Identifier'], $property->types);
        self::assertTrue($property->promoted);
        self::assertSame('identifier', substr($source, $property->nameStartOffset, $property->nameEndOffset - $property->nameStartOffset));
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
        $creations = $document->objectCreations;

        self::assertSame(['array_name', 'self_name', 'this_name', 'property_name', 'untyped_name', 'static_name', 'empty_name'], array_map(static fn ($creation): ?string => $creation->namedOrPositionalArgument('name', 0)?->stringLiteral?->value, $creations));
        self::assertSame(array_fill(0, 7, 'getFunctions'), array_map(static fn ($creation): ?string => $creation->enclosingMethod, $creations));
        self::assertSame([
            ['App\Twig\Runtime\AppRuntime', 'fromArray'],
            ['App\Twig\AppExtension', 'ownMethod'],
            ['App\Twig\AppExtension', 'ownMethod'],
            [null, null],
            [null, null],
            ['App\Twig\Runtime\AppRuntime', 'fromStatic'],
            [null, null],
        ], array_map(static fn ($creation): array => [
            $creation->namedOrPositionalArgument('callable', 1)?->callable?->className,
            $creation->namedOrPositionalArgument('callable', 1)?->callable?->method,
        ], $creations));
        $method = array_values(array_filter($document->methodDeclarations, static fn ($method): bool => 'ownMethod' === $method->name))[0];
        self::assertSame('App\Twig\AppExtension', $method->className);
        self::assertSame("public function ownMethod(string \$value = 'a  b'): string", $method->signature);
        self::assertSame('Formats the value.', $method->description);
    }

    public function testDoesNotResolveAnonymousClassSelfCallablesToTheOuterClass(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Twig;

            use Twig\TwigFunction;

            final class AppExtension
            {
                public function anonymous(): object
                {
                    return new class {
                        public function getFunctions(): array
                        {
                            return [new TwigFunction('anonymous', [self::class, 'render'])];
                        }
                    };
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $callable = $document->objectCreations[0]->positionalArgument(1)?->callable;

        self::assertNull($callable);
        self::assertSame([], $document->classReferences);
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

                public function anonymous(): object
                {
                    return new class {
                        #[FunctionAttribute('anonymous')]
                        public function format(string $value): string
                        {
                            return $value;
                        }
                    };
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $methods = $document->methodDeclarations;

        self::assertSame(['format', 'union', 'hidden', 'parameter', 'anonymous'], array_map(static fn (PhpMethodDeclaration $method): string => $method->name, $methods));
        self::assertSame($document->attributes[0], $methods[0]->attributes[0]);
        self::assertSame('Twig\Attribute\AsTwigFunction', $methods[0]->attributes[0]->name);
        self::assertSame('format', $methods[0]->attributes[0]->positionalArgument(0)?->stringLiteral?->value);
        self::assertSame('Twig\Environment', $methods[0]->firstParameterType);
        self::assertFalse($methods[0]->firstParameterVariadic);
        self::assertTrue($methods[0]->variadic);
        self::assertTrue($methods[0]->public);
        self::assertStringStartsWith('public function format', $methods[0]->signature);
        self::assertNull($methods[1]->firstParameterType);
        self::assertFalse($methods[2]->public);
        self::assertSame([], $methods[3]->attributes);
        self::assertSame('string', $methods[3]->firstParameterType);
    }

    public function testDoesNotUsePlainCommentsAsMethodDescriptions(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            final class Formatter
            {
                // This comment isn't documentation.
                public function format(string $value): string
                {
                    return $value;
                }
            }
            PHP;

        $method = (new TolerantPhpParser(new Parser()))->parse($source)->methodDeclarations[0];

        self::assertNull($method->description);
    }

    public function testExposesInterfaceAndEnumMethodsWithCleanDescriptions(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;

            interface Formatter
            {
                /**
                 * Formats a value.
                 *
                 * @param string $value
                 */
                public function format(string $value): string;
            }

            enum Status
            {
                /**
                 * Returns the display label.
                 *
                 * @return string
                 */
                public function label(): string
                {
                    return $this->name;
                }
            }
            PHP;

        $methods = (new TolerantPhpParser(new Parser()))->parse($source)->methodDeclarations;

        self::assertSame(['App\Formatter', 'App\Status'], array_map(static fn (PhpMethodDeclaration $method): string => $method->className, $methods));
        self::assertSame(['format', 'label'], array_map(static fn (PhpMethodDeclaration $method): string => $method->name, $methods));
        self::assertSame(['Formats a value.', 'Returns the display label.'], array_map(static fn (PhpMethodDeclaration $method): ?string => $method->description, $methods));
        self::assertSame(['public function format(string $value): string', 'public function label(): string'], array_map(static fn (PhpMethodDeclaration $method): string => $method->signature, $methods));
    }

    public function testExposesClassConstantsAndEnumCases(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Model;

            /** Describes the status. */
            enum Status: string
            {
                /** The published state. */
                case Published = 'published';

                public const LABEL = 'Status';
                private const SECRET = 'secret';
            }

            // This isn't documentation.
            interface Options
            {
                public const FORMAT = 'json', EXTENSION = '.json';
            }

            final class Factory
            {
                public function create(): object
                {
                    return new class {
                        public const HIDDEN = 'hidden';
                    };
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $status = $document->typeDeclarations[0];
        $constants = $document->constantDeclarations;

        self::assertSame(PhpTypeKind::Enum, $status->kind);
        self::assertTrue($status->isEnum());
        self::assertSame('enum Status: string', $status->signature);
        self::assertSame('Describes the status.', $status->description);
        self::assertNull($document->typeDeclarations[1]->description);
        self::assertSame([
            [PhpConstantKind::EnumCase, 'App\Model\Status', 'Published', 'case Published;', 'The published state.', true],
            [PhpConstantKind::ClassConstant, 'App\Model\Status', 'LABEL', 'public const LABEL;', null, true],
            [PhpConstantKind::ClassConstant, 'App\Model\Status', 'SECRET', 'private const SECRET;', null, false],
            [PhpConstantKind::ClassConstant, 'App\Model\Options', 'FORMAT', 'public const FORMAT;', null, true],
            [PhpConstantKind::ClassConstant, 'App\Model\Options', 'EXTENSION', 'public const EXTENSION;', null, true],
        ], array_map(static fn ($constant): array => [
            $constant->kind,
            $constant->className,
            $constant->name,
            $constant->signature,
            $constant->description,
            $constant->public,
        ], $constants));
        foreach ($constants as $constant) {
            self::assertSame($constant->name, substr($source, $constant->nameStartOffset, $constant->nameEndOffset - $constant->nameStartOffset));
        }
    }

    public function testKeepsImportsFromIncompleteGroupedSyntax(): void
    {
        $document = (new TolerantPhpParser(new Parser()))->parse("<?php\n// café\nnamespace App;\nuse Vendor\\Package\\{First, Second as Alias");

        self::assertSame([
            'First' => 'Vendor\Package\First',
            'Alias' => 'Vendor\Package\Second',
        ], $document->imports());
    }

    public function testKeepsFactsBeforeTruncatedSource(): void
    {
        $source = file_get_contents(__DIR__.'/../../Fixtures/Parser/php/boundary.php.txt');
        if (false === $source) {
            self::fail('Unable to read the PHP boundary fixture.');
        }

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $creation = $document->objectCreations[0];
        $literal = $creation->argument('name')?->stringLiteral;

        self::assertSame('App\Handler', $document->typeDeclarations[0]->name);
        self::assertSame(['__construct', '__invoke', 'draft'], array_map(static fn ($method): string => $method->name, $document->methodDeclarations));
        self::assertSame('$this->bus', $document->methodCalls[0]->receiver);
        self::assertSame('dispatch', $document->methodCalls[0]->method);
        self::assertSame('Vendor\Package\Message', $creation->className);
        self::assertSame('__invoke', $creation->enclosingMethod);
        self::assertInstanceOf(PhpStringLiteral::class, $literal);
        self::assertSame('café', $literal->value);
        self::assertSame('café', substr($source, $literal->startOffset, $literal->endOffset - $literal->startOffset));
        self::assertCount(6, $document->diagnostics);
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

        self::assertNull($document->attributes[0]->argument('name')?->stringLiteral);
        self::assertCount(1, $document->diagnostics);
        self::assertSame("'}' expected.", $document->diagnostics[0]->message);
    }

    public function testPositionalArgumentsRejectNamedCandidates(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            use App\Attribute\AsThing;
            #[AsThing(option: 'first', 'second')]
            final class Sample
            {
            }
            PHP;

        $attribute = (new TolerantPhpParser(new Parser()))->parse($source)->attributes[0];

        self::assertNull($attribute->positionalArgument(0));
        self::assertSame('second', $attribute->positionalArgument(1)?->stringLiteral?->value);
        self::assertSame('first', $attribute->argument('option')?->stringLiteral?->value);
        self::assertNull($attribute->argument('missing'));
    }

    public function testPositionalArgumentsRejectSpreadCandidates(): void
    {
        $source = <<<'PHP'
            <?php
            $service->run(...$arguments, 'second');
            PHP;

        $call = (new TolerantPhpParser(new Parser()))->parse($source)->methodCalls[0];

        self::assertTrue($call->arguments[0]->unpacked);
        self::assertNull($call->positionalArgument(0));
        self::assertNull($call->namedOrPositionalArgument('first', 0));
        self::assertSame('second', $call->positionalArgument(1)?->stringLiteral?->value);
    }

    public function testFindsClassReferencesAndObjectCreationsInsideArguments(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            use App\Entity\Article;
            use App\Entity\Comment;
            use App\Message\Created;
            final class Sample
            {
                public function run(Registry $registry, Bus $bus): void
                {
                    $registry->single(Article::class);
                    $registry->pair([Article::class, Comment::class]);
                    $bus->dispatch(new Created('body'));
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        [$single, $pair, $dispatch] = $document->methodCalls;

        self::assertSame('App\Entity\Article', $document->soleClassReference($single->positionalArgument(0))?->className);
        self::assertNull($document->soleClassReference($pair->positionalArgument(0)));
        self::assertSame('App\Entity\Article', $document->firstClassReference($pair->positionalArgument(0))?->className);
        self::assertNull($document->soleClassReference(null));
        $creation = $document->firstObjectCreation($dispatch->positionalArgument(0));
        self::assertSame('App\Message\Created', $creation?->className);
        self::assertSame('new Created(\'body\')', substr($source, $creation->startOffset, $creation->endOffset - $creation->startOffset));
        self::assertSame('Created', substr($source, $creation->classNameStartOffset, $creation->classNameEndOffset - $creation->classNameStartOffset));
        self::assertNull($document->firstObjectCreation($single->positionalArgument(0)));
    }

    public function testResolvesReceiverVariablesWithinTheirScopes(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            use Psr\Log\LoggerInterface;
            final class First
            {
                public function __construct(private readonly LoggerInterface $logger)
                {
                }

                public function direct(LoggerInterface $output): void
                {
                    $output->info('a');
                    $this->logger->info('b');
                }

                public function other(string $output): void
                {
                    $output->info('c');
                }
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        [$direct, $property, $unrelated] = $document->methodCalls;

        self::assertSame([['Psr\Log\LoggerInterface']], array_map(static fn ($variable): array => $variable->types, $document->receiverVariables($direct)));
        self::assertSame([['Psr\Log\LoggerInterface']], array_map(static fn ($variable): array => $variable->types, $document->receiverVariables($property)));
        self::assertSame([['string']], array_map(static fn ($variable): array => $variable->types, $document->receiverVariables($unrelated)));
    }

    public function testFiltersAttributesByTarget(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App;
            use App\Attribute\OnClass;
            use App\Attribute\OnMethod;
            #[OnClass]
            final class First
            {
                #[OnMethod]
                public function handle(): void
                {
                }
            }
            #[OnClass]
            final class Second
            {
            }
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);

        self::assertCount(1, $document->attributesOn(PhpAttributeTargetKind::Type, 'App\First'));
        self::assertCount(1, $document->attributesOn(PhpAttributeTargetKind::Type, 'App\Second'));
        self::assertSame([], $document->attributesOn(PhpAttributeTargetKind::Type, 'App\Third'));
        self::assertSame(['App\Attribute\OnMethod'], array_map(static fn ($attribute): string => $attribute->name, $document->attributesOn(PhpAttributeTargetKind::Method, 'App\First', 'handle')));
        self::assertSame([], $document->attributesOn(PhpAttributeTargetKind::Method, 'App\First', 'missing'));
    }
}
