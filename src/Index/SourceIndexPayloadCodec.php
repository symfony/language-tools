<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReference;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbol;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\ParameterDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;
use Symfony\Lsp\Feature\Environment\EnvironmentDeclaration;
use Symfony\Lsp\Feature\Environment\EnvironmentReference;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceFacts;
use Symfony\Lsp\Feature\Event\EventSourceFacts;
use Symfony\Lsp\Feature\Event\EventSourceSymbol;
use Symfony\Lsp\Feature\Event\InvalidEventListenerMethod;
use Symfony\Lsp\Feature\Messenger\MessengerSourceFacts;
use Symfony\Lsp\Feature\Messenger\MessengerSourceSymbol;
use Symfony\Lsp\Feature\Messenger\MessengerSymbolKind;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceSymbol;
use Symfony\Lsp\Feature\Security\SecuritySymbolKind;
use Symfony\Lsp\Feature\Translation\TranslationDeclaration;
use Symfony\Lsp\Feature\Translation\TranslationReference;
use Symfony\Lsp\Feature\Translation\TranslationSourceFacts;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateSourceFacts;

final class SourceIndexPayloadCodec
{
    private const ALLOWED_CLASSES = [
        Position::class,
        Range::class,
        DependencyInjectionReference::class,
        DependencyInjectionSourceFacts::class,
        DependencyInjectionSymbol::class,
        DependencyInjectionSymbolKind::class,
        ParameterDeclaration::class,
        PhpClassDeclaration::class,
        ServiceDeclaration::class,
        EnvironmentDeclaration::class,
        EnvironmentReference::class,
        EnvironmentSourceFacts::class,
        EventSourceFacts::class,
        EventSourceSymbol::class,
        InvalidEventListenerMethod::class,
        MessengerSourceFacts::class,
        MessengerSourceSymbol::class,
        MessengerSymbolKind::class,
        RouteDeclaration::class,
        RouteReferenceLocation::class,
        RouteSourceFacts::class,
        SecuritySourceFacts::class,
        SecuritySourceSymbol::class,
        SecuritySymbolKind::class,
        TranslationDeclaration::class,
        TranslationReference::class,
        TranslationSourceFacts::class,
        TemplateDeclaration::class,
        TemplateReference::class,
        TemplateSourceFacts::class,
    ];

    public function encode(mixed $data): string
    {
        return base64_encode(serialize($data));
    }

    public function decode(string $payload): mixed
    {
        $serialized = base64_decode($payload, true);
        if (false === $serialized) {
            throw new \UnexpectedValueException('The source index payload is not valid base64.');
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \UnexpectedValueException($message);
        });
        try {
            return unserialize($serialized, ['allowed_classes' => self::ALLOWED_CLASSES]);
        } finally {
            restore_error_handler();
        }
    }
}
