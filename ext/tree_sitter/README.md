# Symfony Language Tools Tree-sitter extension

This PHP extension bundles only the Tree-sitter runtime and the grammars needed by Symfony Language Tools. It performs no network access at runtime.

The vendored sources are pinned to:

| Source | Revision | License |
| --- | --- | --- |
| `tree-sitter/tree-sitter` | `v0.26.11` (`64402de2857cc197ecc4ca3bc144ea91fda7e72e`) | MIT |
| `gbprod/tree-sitter-twig` | `2208d2a3c3ee7ef378e97df2e51c18feb7ee9dfc` | MIT |
| `tree-sitter-grammars/tree-sitter-yaml` | `a1c4812a73ec5e089de8e441fdea3a921e8d5079` | MIT |

The Twig grammar was selected because it recognizes extension tags generically, preserves useful nodes around incomplete Twig, has a maintained parser corpus, and has a substantially smaller generated parser than the evaluated alternative. The YAML grammar accepts Symfony custom tags without application-specific grammar changes.

The vendored Twig parser is copied verbatim from the pinned revision: `src/parser.c` and `src/tree_sitter/*.h` into `vendor/twig/src/`, and `LICENSE` into `licenses/tree-sitter-twig.txt`.

Build the extension from the repository root:

```console
$ composer tree-sitter:build
```

Run the parser benchmark with:

```console
$ composer tree-sitter:benchmark
```
