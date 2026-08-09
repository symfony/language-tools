<?php

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class ReferenceShowcase extends AbstractController
{
    public function __invoke(): void
    {
        $this->generateUrl('fixture_home');
        $this->redirectToRoute('fixture_home');
    }
}
