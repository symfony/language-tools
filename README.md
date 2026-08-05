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

A source checkout requires PHP 8.4 or later, Composer 2, Node.js, npm, and a C
build toolchain:

```console
composer install
composer tree-sitter:build
composer test
composer phpstan
composer cs-check
```

## License

Symfony LSP is available under the [MIT License](LICENSE).
