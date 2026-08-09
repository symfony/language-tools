<?php

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class TemplateShowcase extends AbstractController
{
    public function __invoke(): void
    {
        $this->render('fixture.html.t');
        $this->render('fixture.html.twig');
        $this->render('missing.html.twig');
    }
}
