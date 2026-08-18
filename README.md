# Symfony Language Tools

Symfony Language Tools adds Symfony-aware completion, hover, navigation,
references, diagnostics, code actions, rename support and code lenses to your
editor. It works alongside a general PHP language server.

Features cover routing, dependency injection, Twig, translations, environment
variables, bundle configuration, Messenger, events, Security, forms,
validation, serializer metadata, AssetMapper, Stimulus, Live Components and
Doctrine. See the [supported integrations](docs/features/index.rst) for details.

## Installation

### Visual Studio Code

Install the self-contained Symfony Language Tools extension from the
[Visual Studio Marketplace](https://marketplace.visualstudio.com/items?itemName=symfony.language-tools):

```console
code --install-extension symfony.language-tools
```

Add `--pre-release` to install a version with a prerelease suffix. See the
[Visual Studio Code guide](docs/editors/vscode.rst) for configuration and
troubleshooting.

### Neovim

Install `symfony-lsp` from a standalone release or with Mason when available,
then enable the `nvim-lspconfig` configuration:

```lua
vim.lsp.enable('symfony_lsp')
```

See the [Neovim guide](docs/editors/neovim.rst) for installation, workspace
trust, configuration and troubleshooting.

### OpenCode

Install `symfony-lsp` from a standalone release, then configure it as a custom
language server. OpenCode can use Symfony-aware diagnostics and navigation while
its coding agent works on the project.

See the [OpenCode guide](docs/editors/opencode.rst) for configuration, workspace
trust, supported features and platform limitations.

### Standalone Server

Download the archive for your platform from
[GitHub Releases](https://github.com/symfony/language-tools/releases). Extract
the self-contained `symfony-lsp` language server. On Unix, verify the server
before configuring your Language Server Protocol client:

```console
./symfony-lsp --version
```

See the [standalone installation guide](docs/index.rst#installing-a-standalone-release)
for supported platforms, checksum verification and source installation.

## Requirements

Symfony Language Tools supports maintained Symfony versions listed in Symfony's
[release metadata](https://symfony.com/releases.json). Runtime indexing requires
the application's Composer dependencies to be installed and a PHP command
compatible with its Symfony version.

## Documentation

- [Supported Symfony integrations](docs/features/index.rst)
- [Visual Studio Code guide](docs/editors/vscode.rst)
- [Neovim guide](docs/editors/neovim.rst)
- [OpenCode guide](docs/editors/opencode.rst)
- [Standalone server guide](docs/index.rst)
- [Changelog](CHANGELOG.md)

## Contributing

This repository uses an issue-first contribution model and does not accept
external pull requests. Read the [contribution guide](CONTRIBUTING.md) before
reporting a bug or requesting a feature. If you use an agent, point it to the
guide and ask it to help prepare the issue using whatever context is most
relevant to your setup, configuration, and use case.

## Security

Read the [security policy](SECURITY.md) to report a potential vulnerability
privately.

## License

Symfony Language Tools is available under the [MIT License](LICENSE).
Distributions also include the applicable
[third-party notices](THIRD_PARTY_NOTICES.md) and license texts.
