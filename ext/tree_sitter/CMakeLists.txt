cmake_minimum_required(VERSION 3.20)
project(symfony_lsp_tree_sitter_cli C)

add_executable(symfony-lsp-tree-sitter
    symfony_lsp_tree_sitter_cli.c
    vendor/tree-sitter/lib/lib.c
    vendor/twig/src/parser.c
    vendor/yaml/src/parser.c
    vendor/yaml/src/scanner.c
)
target_compile_features(symfony-lsp-tree-sitter PRIVATE c_std_11)
if(MSVC)
    target_compile_options(symfony-lsp-tree-sitter PRIVATE /O2)
else()
    target_compile_options(symfony-lsp-tree-sitter PRIVATE -O2)
endif()
