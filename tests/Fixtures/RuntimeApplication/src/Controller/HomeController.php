<?php

namespace App\Controller;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Response;

#[IsGranted('ROLE_ADMIN')]
final class HomeController
{
    public function __invoke(): Response
    {
        return new Response('Symfony LSP compatibility fixture');
    }
}
