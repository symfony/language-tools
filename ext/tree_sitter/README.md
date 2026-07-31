# Symfony LSP Tree-sitter extension

This PHP extension bundles only the Tree-sitter runtime and the grammars needed by Symfony LSP. It performs no network access at runtime.

The vendored sources are pinned to:

| Source | Revision | License |
| --- | --- | --- |
| `tree-sitter/tree-sitter` | `v0.26.11` (`64402de2857cc197ecc4ca3bc144ea91fda7e72e`) | MIT |
| `gbprod/tree-sitter-twig` | `0afd9a6d808944e65a7be393e31868b85345735f` | WTFPL |
| `tree-sitter-grammars/tree-sitter-yaml` | `a1c4812a73ec5e089de8e441fdea3a921e8d5079` | MIT |

The Twig grammar was selected because it recognizes extension tags generically, preserves useful nodes around incomplete Twig, has a maintained parser corpus, and has a substantially smaller generated parser than the evaluated alternative. The YAML grammar accepts Symfony custom tags without application-specific grammar changes.

Build the extension from the repository root:

```console
$ composer tree-sitter:build
```

Run the parser benchmark with:

```console
$ composer tree-sitter:benchmark
```
