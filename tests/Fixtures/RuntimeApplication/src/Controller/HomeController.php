<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

final class HomeController
{
    public function __invoke(): Response
    {
        return new Response('Symfony LSP compatibility fixture');
    }
}
