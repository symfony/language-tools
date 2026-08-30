<?php

namespace Symfony\Lsp\Parser\Yaml;

enum YamlScalarStyle: string
{
    case Plain = 'plain';
    case SingleQuoted = 'single-quoted';
    case DoubleQuoted = 'double-quoted';
    case BlockLiteral = 'block-literal';
    case BlockFolded = 'block-folded';
}
