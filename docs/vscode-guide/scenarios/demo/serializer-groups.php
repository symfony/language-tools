<?php

namespace App\VisualGuide;

use Symfony\Component\Serializer\Attribute\Groups;

final class SerializerGroups
{
    #[Groups(['admin'])]
    public string $email;
}
