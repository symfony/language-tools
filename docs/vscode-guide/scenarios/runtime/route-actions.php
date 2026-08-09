<?php

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class RouteActionShowcase extends AbstractController
{
    public function __invoke(): void
    {
        $this->generateUrl('fixture_article');
    }
}
