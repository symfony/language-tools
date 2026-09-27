<?php

namespace Symfony\Lsp\Feature\Twig;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

final class TwigCallablePhpDocParser
{
    private const CACHE_SIZE = 16;

    private readonly Lexer $lexer;
    private readonly PhpDocParser $parser;

    /** @var array<string, PhpDocNode> */
    private array $cache = [];

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constants = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
    }

    public function parse(string $docComment): PhpDocNode
    {
        $key = hash('xxh128', $docComment);
        if (isset($this->cache[$key])) {
            $node = $this->cache[$key];
            unset($this->cache[$key]);

            return $this->cache[$key] = $node;
        }
        $node = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        if (\count($this->cache) >= self::CACHE_SIZE) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        return $this->cache[$key] = $node;
    }
}
