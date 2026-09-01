Using Symfony Language Tools with Neovim
========================================

Symfony Language Tools uses Neovim's built-in LSP client through the
conventional ``nvim-lspconfig`` configuration. Install the language server
separately, then enable its configuration in Neovim.

Installing the Server
---------------------

Install the ``symfony-lsp`` package with Mason when your registry includes it:

.. code-block:: vim

    :MasonInstall symfony-lsp

Alternatively, use the `standalone guide`_ to download a release and make
``symfony-lsp`` available on ``PATH``.

Enabling the Language Server
----------------------------

Install `nvim-lspconfig`_, then enable Symfony Language Tools from ``init.lua``:

.. code-block:: lua

    vim.lsp.enable('symfony_lsp')

If your installed ``nvim-lspconfig`` version doesn't include ``symfony_lsp``,
copy ``editor/neovim/lsp/symfony_lsp.lua`` from this repository to
``lsp/symfony_lsp.lua`` in your Neovim configuration directory.

Symfony Language Tools starts for PHP, Twig, YAML, JSON, XML, JavaScript,
TypeScript and dotenv buffers under a ``composer.json`` or Git workspace. Neovim
recognizes ``.twig`` files without another plugin. Keep a general PHP language
server active for PHP types, diagnostics and non-Symfony completion.

Workspace Trust
---------------

Symfony Language Tools asks before executing application code when no trust
decision was configured. Accept the prompt only for a workspace whose code you
trust. The decision lasts for the current language server process.

Set an explicit decision when the Neovim configuration is already scoped to a
trusted project:

.. code-block:: lua

    vim.lsp.config('symfony_lsp', {
        init_options = {
            workspaceTrust = true,
        },
    })

    vim.lsp.enable('symfony_lsp')

Set ``workspaceTrust = false`` to keep every project in static-only mode. You
can use Neovim's trusted local configuration support to keep this decision in a
project ``.nvim.lua`` file rather than enabling runtime indexing globally.

Configuration
-------------

Put shared settings in ``.symfony-lsp.json``; see the
`project configuration`_.

Override settings for Neovim only before enabling the language server:

.. code-block:: lua

    vim.lsp.config('symfony_lsp', {
        init_options = {
            workspaceTrust = true,
            trace = 'off',
        },
        settings = {
            symfonyLsp = {
                environment = 'test',
            },
        },
    })

    vim.lsp.enable('symfony_lsp')

Use the same setting names as ``.symfony-lsp.json`` for client-only overrides.
See `Docker support`_ when the PHP command runs in a container. Restart the
language client after changing its configuration.

Set the ``SYMFONY_LSP_MEMORY_LIMIT`` environment variable to change the
server's PHP memory limit for large projects:

.. code-block:: lua

    vim.lsp.config('symfony_lsp', {
        cmd_env = { SYMFONY_LSP_MEMORY_LIMIT = '4G' },
    })

Code Lenses
-----------

Neovim maps ``grx`` to code lens execution by default. Symfony code lenses open
their related locations in the quickfix list.

Troubleshooting
---------------

Run ``:checkhealth vim.lsp`` first. Confirm that ``symfony-lsp`` is available
on ``PATH``, the buffer has one ``symfony_lsp`` client and the project contains
a FrameworkBundle requirement.

Set ``trace`` to ``messages`` or ``verbose`` temporarily to add redacted
protocol traffic to Neovim's LSP log. The ``verbose`` level also records
sanitized runtime section failure causes with relative code locations and
argument-free frames. Restore it to ``off`` after troubleshooting.

.. _`standalone guide`: ../index.rst#installing-a-standalone-release
.. _`project configuration`: ../project-configuration.rst
.. _`Docker support`: ../docker.rst
.. _`nvim-lspconfig`: https://github.com/neovim/nvim-lspconfig
