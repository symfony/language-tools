PHP_ARG_ENABLE([symfony_lsp_tree_sitter],
  [whether to enable the Symfony LSP Tree-sitter extension],
  [AS_HELP_STRING([--enable-symfony-lsp-tree-sitter],
    [Enable Symfony LSP Tree-sitter support])],
  [no])

if test "$PHP_SYMFONY_LSP_TREE_SITTER" != "no"; then
  PHP_NEW_EXTENSION([symfony_lsp_tree_sitter], [
    symfony_lsp_tree_sitter.c
    vendor/tree-sitter/lib/lib.c
    vendor/twig/src/parser.c
    vendor/yaml/src/parser.c
    vendor/yaml/src/scanner.c
  ], [$ext_shared],, [-std=c11])
fi
