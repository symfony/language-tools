# Symfony Language Tools

Symfony Language Tools makes your editor understand Symfony: route names,
service IDs, template names, translation keys, environment variables, bundle
configuration and more get completion, hover, navigation, references, rename
and diagnostics. It works alongside a general PHP language server.

Use it two ways:

- [in your editor](docs/editors/index.rst), while you write code;
- [on the command line](docs/check.rst), to check a project in CI or before a
  commit.

See the [supported integrations](docs/features/index.rst) for what it knows
about Symfony.

## Installation

### Visual Studio Code

Install the self-contained extension from the
[Visual Studio Marketplace](https://marketplace.visualstudio.com/items?itemName=symfony.language-tools):

```console
code --install-extension symfony.language-tools
```

Add `--pre-release` for prerelease versions. VSCodium users can install the
matching `.vsix` from
[GitHub Releases](https://github.com/symfony/language-tools/releases). See the
[VS Code guide](docs/editors/vscode.rst).

### Neovim

Install `symfony-lsp` from a release or with Mason, then enable it:

```lua
vim.lsp.enable('symfony_lsp')
```

See the [Neovim guide](docs/editors/neovim.rst).

### Zed

The extension isn't in Zed's registry yet. Install it from source by following
the [Zed guide](docs/editors/zed.rst). Linux and Apple Silicon macOS only.

### OpenCode

Install `symfony-lsp`, then declare it as a custom language server so the
coding agent gets Symfony diagnostics and navigation. See the
[OpenCode guide](docs/editors/opencode.rst).

### Standalone server

Download the archive for your platform from
[GitHub Releases](https://github.com/symfony/language-tools/releases), verify
it against `SHA256SUMS` and extract the self-contained `symfony-lsp`
executable:

```console
./symfony-lsp --version
```

See the [installation guide](docs/index.rst) for supported platforms and
building from source.

## Continuous Integration

Check saved files without an editor:

```console
symfony-lsp check --format=github
```

Symfony CLI can manage the executable for you with `symfony lsp:check`. The
checker reports in human, JSON, GitHub Actions, GitLab Code Quality and SARIF
formats, blocks on the codes you choose and accepts existing findings through
a baseline. It runs your application unless you pass `--source-only`. See the
[command-line guide](docs/check.rst).

## Requirements

An application on a maintained Symfony version, with its Composer dependencies
installed and a PHP command compatible with it. That command can run in a
container; see [Docker support](docs/docker.rst).

## Tested with Real Applications

Symfony Language Tools is continuously tested with
[Kimai](https://github.com/kimai/kimai),
[Mautic](https://github.com/mautic/mautic),
[Sulu Demo](https://github.com/sulu/sulu-demo),
[Sulu Skeleton](https://github.com/sulu/skeleton),
[Sylius](https://github.com/Sylius/Sylius),
[Shopware](https://github.com/shopware/shopware),
[Pimcore Skeleton](https://github.com/pimcore/skeleton) and
[Symfony Demo](https://github.com/symfony/demo) across supported Symfony
versions.

## Documentation

- [Installation](docs/index.rst)
- [Using it in your editor](docs/editors/index.rst)
- [Checking a project from the command line](docs/check.rst)
- [Supported Symfony integrations](docs/features/index.rst)
- [Project configuration](docs/configuration.rst)
- [Docker support](docs/docker.rst)
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
