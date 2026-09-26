<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class YamlMetadataProviderTest extends MetadataTestCase
{
    public function testCompletesMappedProperties(): void
    {
        $kit = (new ProjectTestKit())->open('file:///workspace/src/Entity/User.php', <<<'PHP'
            <?php
            namespace App\Entity;
            final class User
            {
                public string $email;
            }
            PHP)->index();
        $propertyUri = 'file:///workspace/config/serializer/Completion.yaml';
        $propertyText = "App\\Entity\\User:\n    attributes:\n        em";
        $kit->open($propertyUri, $propertyText);

        self::assertSame(['email'], $kit->labels($kit->get(MetadataCompletionProvider::class)->complete($kit->positioned($kit->offset($propertyUri, \strlen($propertyText))))));
    }

    public function testCompletesPropertiesAfterAPropertyWithConstraints(): void
    {
        $kit = (new ProjectTestKit())->open('file:///workspace/src/Entity/User.php', <<<'PHP'
            <?php
            namespace App\Entity;
            final class User
            {
                public string $password;
                public string $email;
            }
            PHP)->index();
        $uri = 'file:///workspace/config/validator/User.yaml';
        $text = "App\\Entity\\User:\n    properties:\n        password:\n            - NotBlank: ~\n        em";
        $kit->open($uri, $text);

        self::assertContains('email', $kit->labels($kit->get(MetadataCompletionProvider::class)->complete($kit->positioned($kit->offset($uri, \strlen($text))))));
    }
}
