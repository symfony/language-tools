Neovim
======

Neovim's built-in LSP client starts Symfony Language Tools through the
``nvim-lspconfig`` configuration. Install the server first, with Mason when
your registry includes it:

.. code-block:: vim

    :MasonInstall symfony-lsp

Otherwise download a release and put ``symfony-lsp`` on ``PATH``; see
`installing the server`_.

Enabling It
-----------

Install `nvim-lspconfig`_, then add this to ``init.lua``:

.. code-block:: lua

    vim.lsp.enable('symfony_lsp')

If your ``nvim-lspconfig`` version doesn't ship ``symfony_lsp``, copy
``editor/neovim/lsp/symfony_lsp.lua`` from this repository into
``lsp/symfony_lsp.lua`` in your Neovim configuration directory.

The server starts for PHP, Twig, YAML, JSON, XML, JavaScript, TypeScript and
dotenv buffers in a workspace containing ``composer.json``. Keep a general PHP
language server enabled alongside it.

Configuration
-------------

Shared settings belong in ``.symfony-lsp.json``; see
`project configuration`_. Override them for Neovim only, and answer the trust
question up front, before enabling the server:

.. code-block:: lua

    vim.lsp.config('symfony_lsp', {
        init_options = {
            workspaceTrust = true,
        },
        settings = {
            symfonyLsp = {
                environment = 'test',
            },
        },
    })

    vim.lsp.enable('symfony_lsp')

Without ``workspaceTrust``, the server asks before running your application,
and the answer lasts until the client stops. Set it to ``false`` to never run
it. Keep the decision in a project-local ``.nvim.lua`` rather than enabling it
globally.

``projectRoots`` and ``trace`` belong to ``init_options``, not ``settings``.
Restart the client after changing its configuration.

Set the server's memory limit through its environment:

.. code-block:: lua

    vim.lsp.config('symfony_lsp', {
        cmd_env = { SYMFONY_LSP_MEMORY_LIMIT = '4G' },
    })

Code Lenses
-----------

Neovim doesn't fetch code lenses unless you enable them with
``vim.lsp.codelens.enable()``. Once enabled, ``grx`` runs the lens under the
cursor and Symfony lenses fill the quickfix list with the related locations.

Troubleshooting
---------------

Run ``:checkhealth vim.lsp`` and confirm that the buffer has a ``symfony_lsp``
client. Neovim's LSP log reports why an application failed to boot. Set
``trace`` to ``messages`` temporarily to add redacted protocol traffic.

.. _`installing the server`: ../index.rst
.. _`project configuration`: ../configuration.rst
.. _`nvim-lspconfig`: https://github.com/neovim/nvim-lspconfig
