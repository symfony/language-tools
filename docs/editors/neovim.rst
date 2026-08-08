Using Symfony LSP with Neovim
=============================

The first-party Neovim plugin configures the language client, installs the
matching standalone server, registers index commands and provides an optional
statusline component. It supports Neovim 0.11.3 or later.

Installing with vim.pack
------------------------

Neovim 0.12 includes ``vim.pack``. Add Symfony LSP to ``init.lua`` and call its
setup function:

.. code-block:: lua

    vim.pack.add({ 'https://github.com/symfony/lsp' })

    require('symfony_lsp').setup()

The first setup downloads the server and Tree-sitter sidecar for the current
platform from the matching GitHub release. The plugin currently installs Symfony
LSP 0.5.0. It verifies the archive against the release's ``SHA256SUMS`` before
installing it under Neovim's data directory. Updating the plugin after a new
server release installs that server without replacing older installations.

Automatic installation requires ``curl`` and ``tar``. Linux also requires
``sha256sum``, macOS requires ``shasum`` and Windows requires ``certutil``.
Run ``:checkhealth symfony_lsp`` to verify these commands and the installed
server.

Installing with lazy.nvim
-------------------------

Add this plugin specification when using `lazy.nvim`_:

.. code-block:: lua

    {
        'symfony/lsp',
        config = function()
            require('symfony_lsp').setup()
        end,
    }

Symfony LSP uses Neovim's built-in LSP client directly. The plugin doesn't
require ``nvim-lspconfig``, Mason or another language client plugin.

Installing the Server with Mason
--------------------------------

The Mason registry provides a ``symfony-lsp`` package when you prefer one
shared executable installation. Install it before setting up the first-party
plugin:

.. code-block:: vim

    :MasonInstall symfony-lsp

Disable the managed downloader so the plugin uses Mason's executable from
``PATH``:

.. code-block:: lua

    require('symfony_lsp').setup({
        auto_install = false,
    })

The package also maps to the ``symfony_lsp`` configuration supplied by
``nvim-lspconfig``. You can use that configuration without the first-party
plugin, but the index commands, managed installer and statusline component are
only available from this repository.

Using a Manually Installed Server
---------------------------------

Set ``cmd`` to use a standalone release, a source checkout or a server managed
by another package manager:

.. code-block:: lua

    require('symfony_lsp').setup({
        cmd = { '/path/to/symfony-lsp' },
        auto_install = false,
    })

Keep ``symfony-lsp-tree-sitter`` next to a standalone server. When using the
source executable with a separately built sidecar, pass its path in the server
environment:

.. code-block:: lua

    require('symfony_lsp').setup({
        cmd = { '/path/to/lsp/bin/symfony-lsp' },
        cmd_env = {
            SYMFONY_LSP_TREE_SITTER =
                '/path/to/lsp/var/build/tree_sitter_cli/'
                .. 'symfony-lsp-tree-sitter',
        },
        auto_install = false,
    })

Run ``:SymfonyLspInstall`` to install a missing server or
``:SymfonyLspInstall!`` to verify and replace the current installation.

Workspace Trust
---------------

Symfony LSP asks before executing application code when no trust decision was
configured. Accept the prompt only for a workspace whose code you trust. The
decision lasts for the current language server process.

Set an explicit decision when the Neovim configuration is already scoped to a
trusted project:

.. code-block:: lua

    require('symfony_lsp').setup({
        workspace_trust = true,
    })

Set ``workspace_trust = false`` to keep every project in static-only mode. You
can use Neovim's trusted local configuration support to keep this decision in a
project ``.nvim.lua`` file rather than enabling runtime indexing globally.

Configuration
-------------

Pass Symfony project settings through ``settings``:

.. code-block:: lua

    require('symfony_lsp').setup({
        workspace_trust = true,
        settings = {
            phpCommand = { 'php' },
            consolePath = 'bin/console',
            environment = 'dev',
            debug = true,
            runtimeIndexing = true,
            translationDiagnostics = false,
        },
        project_roots = {},
        trace = 'off',
    })

``phpCommand`` is an argument list used to run the project bridge. For example,
use ``{ 'symfony', 'php' }`` with Symfony CLI or
``{ 'ddev', 'exec', 'php' }`` with DDEV.

``consolePath``, ``environment``, ``debug``, ``runtimeIndexing``,
``project_roots``, ``trace`` and ``translationDiagnostics`` have the same
behavior as their VS Code counterparts. Restart the language client after
changing setup options.

The plugin starts for PHP, Twig, YAML, JSON, XML, JavaScript, TypeScript and
dotenv buffers under a ``composer.json`` or Git workspace. Neovim recognizes
``.twig`` files without another plugin. Keep a general PHP language server
active for PHP types, diagnostics and non-Symfony completion.

Index Commands
--------------

The plugin defines these commands:

* ``:SymfonyLspRefreshIndex`` rebuilds the current project's indexes;
* ``:SymfonyLspIndexStatus`` reports every discovered project's state;
* ``:SymfonyLspSwitchEnvironment [environment]`` selects an environment and
  rebuilds runtime metadata;
* ``:SymfonyLspInstall[!]`` installs or replaces the matching server.

Neovim maps ``grx`` to code lens execution by default. Symfony code lenses open
their related locations in the quickfix list.

Statusline
----------

Enable the built-in statusline integration to append the Symfony index state:

.. code-block:: lua

    require('symfony_lsp').setup({
        statusline = true,
    })

The component reports installation, indexing, static-only, stale, failed and
ready states. A ready runtime index includes its Symfony environment.

Use the component directly with a statusline plugin. For example, add it to
`lualine.nvim`_:

.. code-block:: lua

    require('lualine').setup({
        sections = {
            lualine_x = {
                require('symfony_lsp').statusline,
            },
        },
    })

Customize status icons without changing the status formatter:

.. code-block:: lua

    require('symfony_lsp').setup({
        status = {
            icons = {
                ready = 'ok',
                indexing = '...',
                stale = 'stale',
                failed = 'failed',
                installing = 'installing',
            },
        },
    })

Troubleshooting
---------------

Run ``:checkhealth symfony_lsp`` and ``:checkhealth vim.lsp`` first. Confirm
that the buffer has one ``symfony_lsp`` client, the project contains a
FrameworkBundle requirement and the installed server version matches the
plugin version.

Use ``:SymfonyLspIndexStatus`` to distinguish project discovery, source index
and runtime index failures. Set ``trace = 'messages'`` or ``trace = 'verbose'``
temporarily to add redacted protocol traffic to Neovim's LSP log. Restore
``trace = 'off'`` after troubleshooting.

.. _`lazy.nvim`: https://github.com/folke/lazy.nvim
.. _`lualine.nvim`: https://github.com/nvim-lualine/lualine.nvim
