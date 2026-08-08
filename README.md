# Symfony LSP

Symfony LSP adds Symfony-aware completion, hover, navigation, references,
diagnostics, code actions, rename support, and code lenses while working
alongside a general PHP language server.

It understands routing, dependency injection, Twig, translations, environment
variables, bundle configuration, Messenger, events, Security, forms,
validation, serializer metadata, AssetMapper, Stimulus, Live Components, and
Doctrine metadata.

## Installation

Install the self-contained VS Code extension from the
[Visual Studio Marketplace](https://marketplace.visualstudio.com/items?itemName=symfonycorp.symfony-lsp):

```console
code --install-extension symfonycorp.symfony-lsp
```

Add `--pre-release` to install a version with a prerelease suffix.

Neovim 0.11.3 or later can install the first-party plugin and matching server
directly from this repository:

```lua
vim.pack.add({ 'https://github.com/symfony/lsp' })
require('symfony_lsp').setup()
```

See the [Neovim guide](docs/editors/neovim.rst) for lazy.nvim, workspace trust,
index commands, statuslines, custom settings and troubleshooting.

Standalone server archives for Linux, macOS, and Windows are available from
[GitHub Releases](https://github.com/symfony/lsp/releases). Each archive contains
the language server and its matching Tree-sitter sidecar.

## Requirements

Symfony LSP supports FrameworkBundle branches listed in Symfony's
[`supported_versions`](https://symfony.com/releases.json) release metadata. The
application needs PHP and Composer so the project bridge can inspect its
compiled Symfony metadata.

## Documentation

Start with the [Symfony LSP documentation](docs/index.rst) for supported
integrations, installation, editor configuration, architecture, testing, and
release procedures.

## Development

A source checkout requires PHP 8.4 or later, Composer 2, Node.js, npm, Neovim
0.11.3 or later, StyLua and a C build toolchain:

```console
composer install
composer tree-sitter:build
composer test
composer phpstan
composer cs-check
stylua --check lsp lua editor/neovim/tests
./tools/test-neovim
```

## License

Symfony LSP is available under the [MIT License](LICENSE).
