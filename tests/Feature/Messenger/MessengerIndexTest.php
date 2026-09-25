<?php

namespace Symfony\Lsp\Tests\Feature\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Messenger\MessengerHandlerDeclaration;
use Symfony\Lsp\Feature\Messenger\MessengerIndex;
use Symfony\Lsp\Feature\Messenger\MessengerMessage;

final class MessengerIndexTest extends TestCase
{
    public function testLooksClassesUpRegardlessOfCaseAndLeadingBackslash(): void
    {
        $index = new MessengerIndex();
        $message = new MessengerMessage('App\\Message\\SendEmail', ['async']);
        $handler = new MessengerHandlerDeclaration('App\\Message\\SendEmail', 'messenger.bus.default', 'App\\Handler\\SendEmailHandler', 'App\\Handler\\SendEmailHandler', '__invoke', 0, null);
        $index->replace([], [], [$message], [$handler], true);

        self::assertSame($message, $index->message('\\app\\message\\SENDEMAIL'));
        self::assertSame([$handler], $index->handlersForMessage('\\App\\Message\\sendemail'));
        self::assertSame([$handler], $index->handlersByClass('\\app\\Handler\\SendEmailHandler'));
    }

    public function testKeepsMessagesSortedByClassName(): void
    {
        $index = new MessengerIndex();
        $index->replace([], [], [
            new MessengerMessage('App\\Message\\second', []),
            new MessengerMessage('App\\Message\\First', []),
        ], [], true);

        self::assertSame(['App\\Message\\First', 'App\\Message\\second'], array_column($index->messages(), 'className'));
    }
}
