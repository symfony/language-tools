<?php

namespace Symfony\Lsp\Feature\Css;

enum CssColorNotation
{
    case Hex;
    case Named;
    case Rgb;
    case Hsl;
    case Hwb;
    case Lab;
    case Lch;
    case Oklab;
    case Oklch;
}
