<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class SourceIndexPayloadCodecTest extends TestCase
{
    public function testRejectsUndeclaredRootPayloadClasses(): void
    {
        $provider = new PayloadCodecProvider([RouteDeclaration::class]);
        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$provider]);
        $this->expectException(\UnexpectedValueException::class);

        $codec->encode($provider->name(), new RouteSourceFacts('file:///source.php', [], []));
    }

    public function testRejectsIncompletePayloadObjectGraphs(): void
    {
        $provider = new PayloadCodecProvider([RouteSourceFacts::class]);
        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$provider]);
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $payload = base64_encode(serialize(new RouteSourceFacts('file:///source.php', [new RouteDeclaration('route', 'file:///source.php', $range)], [])));
        $this->expectException(\UnexpectedValueException::class);

        $codec->decode($provider->name(), $payload);
    }

    public function testRejectsCachedPayloadsWithUninitializedProperties(): void
    {
        $provider = new PayloadCodecProvider([UninitializedPayloadFacts::class]);
        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$provider]);
        $facts = (new \ReflectionClass(UninitializedPayloadFacts::class))->newInstanceWithoutConstructor();
        $payload = base64_encode(serialize($facts));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('uninitialized property');

        $codec->decode($provider->name(), $payload);
    }
}

final class PayloadCodecProvider implements SourceIndexProviderInterface
{
    /** @param list<string> $payloadClasses */
    public function __construct(private readonly array $payloadClasses)
    {
    }

    public function name(): string
    {
        return 'payload';
    }

    public function payloadClasses(): array
    {
        return $this->payloadClasses;
    }

    public function begin(Project $project): void
    {
    }

    public function index(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function restore(Project $project, mixed $data): void
    {
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return [];
    }

    public function remove(Project $project, string $uri): void
    {
    }

    public function overlay(Project $project, Document $document): void
    {
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }
}

final class UninitializedPayloadFacts implements SourceFactsInterface
{
    public string $uri;

    public function isEmpty(): bool
    {
        return false;
    }
}
