<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Asset\AssetSourceFacts;
use Symfony\Lsp\Feature\Asset\AssetSourceSymbol;
use Symfony\Lsp\Feature\Asset\AssetSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReference;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbol;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\ParameterDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;
use Symfony\Lsp\Feature\Doctrine\DoctrineEntity;
use Symfony\Lsp\Feature\Doctrine\DoctrineField;
use Symfony\Lsp\Feature\Doctrine\DoctrineRepository;
use Symfony\Lsp\Feature\Doctrine\DoctrineSourceFacts;
use Symfony\Lsp\Feature\Doctrine\DoctrineSourceSymbol;
use Symfony\Lsp\Feature\Doctrine\DoctrineSymbolKind;
use Symfony\Lsp\Feature\Environment\EnvironmentDeclaration;
use Symfony\Lsp\Feature\Environment\EnvironmentReference;
use Symfony\Lsp\Feature\Environment\EnvironmentSourceFacts;
use Symfony\Lsp\Feature\Event\EventSourceFacts;
use Symfony\Lsp\Feature\Event\EventSourceSymbol;
use Symfony\Lsp\Feature\Event\InvalidEventListenerMethod;
use Symfony\Lsp\Feature\Messenger\MessengerSourceFacts;
use Symfony\Lsp\Feature\Messenger\MessengerSourceSymbol;
use Symfony\Lsp\Feature\Messenger\MessengerSymbolKind;
use Symfony\Lsp\Feature\Metadata\MetadataSourceFacts;
use Symfony\Lsp\Feature\Metadata\MetadataSourceSymbol;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceFacts;
use Symfony\Lsp\Feature\Security\SecuritySourceSymbol;
use Symfony\Lsp\Feature\Security\SecuritySymbolKind;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerDeclaration;
use Symfony\Lsp\Feature\Stimulus\StimulusMember;
use Symfony\Lsp\Feature\Stimulus\StimulusMemberKind;
use Symfony\Lsp\Feature\Stimulus\StimulusReference;
use Symfony\Lsp\Feature\Stimulus\StimulusSourceFacts;
use Symfony\Lsp\Feature\Translation\TranslationDeclaration;
use Symfony\Lsp\Feature\Translation\TranslationReference;
use Symfony\Lsp\Feature\Translation\TranslationSourceFacts;
use Symfony\Lsp\Feature\Twig\LiveComponentEvent;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigComponent;
use Symfony\Lsp\Feature\Twig\TwigComponentAction;
use Symfony\Lsp\Feature\Twig\TwigComponentActionReference;
use Symfony\Lsp\Feature\Twig\TwigComponentReference;
use Symfony\Lsp\Feature\Twig\TwigComponentSourceFacts;

final class SourceIndexPayloadCodec
{
    private const ALLOWED_CLASSES = [
        Position::class,
        Range::class,
        AssetSourceFacts::class,
        AssetSourceSymbol::class,
        AssetSymbolKind::class,
        DependencyInjectionReference::class,
        DependencyInjectionSourceFacts::class,
        DependencyInjectionSymbol::class,
        DependencyInjectionSymbolKind::class,
        ParameterDeclaration::class,
        PhpClassDeclaration::class,
        ServiceDeclaration::class,
        DoctrineEntity::class,
        DoctrineField::class,
        DoctrineRepository::class,
        DoctrineSourceFacts::class,
        DoctrineSourceSymbol::class,
        DoctrineSymbolKind::class,
        EnvironmentDeclaration::class,
        EnvironmentReference::class,
        EnvironmentSourceFacts::class,
        EventSourceFacts::class,
        EventSourceSymbol::class,
        InvalidEventListenerMethod::class,
        MessengerSourceFacts::class,
        MessengerSourceSymbol::class,
        MessengerSymbolKind::class,
        MetadataSourceFacts::class,
        MetadataSourceSymbol::class,
        MetadataSymbolKind::class,
        RouteDeclaration::class,
        RouteReferenceLocation::class,
        RouteSourceFacts::class,
        SecuritySourceFacts::class,
        SecuritySourceSymbol::class,
        SecuritySymbolKind::class,
        StimulusControllerDeclaration::class,
        StimulusMember::class,
        StimulusMemberKind::class,
        StimulusReference::class,
        StimulusSourceFacts::class,
        TranslationDeclaration::class,
        TranslationReference::class,
        TranslationSourceFacts::class,
        TemplateDeclaration::class,
        LiveComponentEvent::class,
        TemplateReference::class,
        TemplateSourceFacts::class,
        TwigComponent::class,
        TwigComponentAction::class,
        TwigComponentActionReference::class,
        TwigComponentReference::class,
        TwigComponentSourceFacts::class,
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
