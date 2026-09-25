<?php

namespace Symfony\Lsp\Tests\Feature\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Event\EventIndex;
use Symfony\Lsp\Feature\Event\EventListener;

final class EventIndexTest extends TestCase
{
    public function testLooksListenerClassesUpRegardlessOfCaseAndLeadingBackslash(): void
    {
        $index = new EventIndex();
        $listener = new EventListener('kernel.request', 'App\\EventListener\\RequestListener', 'onKernelRequest', 0);
        $index->replace([], [$listener], true);

        self::assertSame([$listener], $index->listenersByClass('\\app\\eventlistener\\RequestListener'));
        self::assertSame([], $index->listenersByClass('App\\EventListener\\OtherListener'));
    }
}
