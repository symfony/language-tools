<?php

namespace Symfony\Lsp\Parser\Php;

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Parser;

final class TolerantPhpParser implements PhpParserInterface
{
    private readonly PhpNameContextBuilder $names;
    private readonly PhpDeclarationFactBuilder $declarations;
    private readonly PhpExpressionFactBuilder $expressions;

    public function __construct(
        private readonly Parser $parser,
    ) {
        $nodes = new TolerantPhpNodeAdapter();
        $scopes = new TolerantPhpScopeResolver($nodes);
        $this->names = new PhpNameContextBuilder($nodes, $scopes);
        $this->expressions = new PhpExpressionFactBuilder($nodes, $scopes);
        $this->declarations = new PhpDeclarationFactBuilder($nodes, $scopes, $this->expressions);
    }

    public function parse(string $source): PhpDocument
    {
        $root = $this->parser->parseSourceFile($source);
        $nodes = new TolerantPhpNodeCollection($root->getDescendantNodes(), $source);
        $names = $this->names->build($nodes, $source);
        $classReferences = $this->expressions->classReferences($nodes, $source, $names);
        $declarations = $this->declarations->build($nodes, $source, $names, $classReferences);
        $expressions = $this->expressions->build($nodes, $source, $names, $classReferences);

        $diagnostics = [];
        foreach (DiagnosticsProvider::getDiagnostics($root) as $diagnostic) {
            $diagnostics[] = new PhpDiagnostic(
                $diagnostic->message,
                $diagnostic->start,
                $diagnostic->start + $diagnostic->length,
            );
        }

        return new PhpDocument(
            $declarations->attributes,
            $expressions->methodCalls,
            $declarations->typeDeclarations,
            $diagnostics,
            $declarations->typedVariables,
            $names,
            $expressions->objectCreations,
            $declarations->methodDeclarations,
            $declarations->constantDeclarations,
            $declarations->propertyDeclarations,
            $expressions->classReferences,
        );
    }
}
