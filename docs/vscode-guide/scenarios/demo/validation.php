<?php

use Symfony\Component\Validator\Constraints as Assert;

final class ValidationShowcase
{
    #[Assert\Length(ma)]
    public string $title;
}
