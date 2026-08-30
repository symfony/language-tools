# Symfony Language Tools Tree-sitter extension

This PHP extension bundles only the Tree-sitter runtime and the grammars needed by Symfony Language Tools. It performs no network access at runtime.

The vendored sources are pinned to:

| Source | Revision | License |
| --- | --- | --- |
| `tree-sitter/tree-sitter` | `v0.26.11` (`64402de2857cc197ecc4ca3bc144ea91fda7e72e`) | MIT |
| `gbprod/tree-sitter-twig` | `0afd9a6d808944e65a7be393e31868b85345735f` with `vendor/twig/string-escapes.patch` | WTFPL |
| `tree-sitter-grammars/tree-sitter-yaml` | `a1c4812a73ec5e089de8e441fdea3a921e8d5079` | MIT |

The Twig grammar was selected because it recognizes extension tags generically, preserves useful nodes around incomplete Twig, has a maintained parser corpus, and has a substantially smaller generated parser than the evaluated alternative. The YAML grammar accepts Symfony custom tags without application-specific grammar changes.

The vendored Twig parser is regenerated from the pinned revision plus `vendor/twig/string-escapes.patch`, which fixes double-escaped string regexes so escaped quotes and generic backslash escapes lex like Twig's own lexer. To regenerate after changing the patch: clone the pinned revision, apply the patch, run `tree-sitter generate --abi 14`, verify `tree-sitter test` still passes the full corpus, and copy `src/parser.c` and `src/tree_sitter/*.h` into `vendor/twig/src/`.

Build the extension from the repository root:

```console
$ composer tree-sitter:build
```

Run the parser benchmark with:

```console
$ composer tree-sitter:benchmark
```
