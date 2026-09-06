<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RouteAliasController
{
    #[Route('/route-alias', name: 'fixture_route_alias')]
    public function index(): Response
    {
        return new Response('Route alias');
    }
}
