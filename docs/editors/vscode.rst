Using Symfony LSP with VS Code
==============================

Symfony LSP is under active development. The bundled VS Code extension makes it
possible to test the language server from a source checkout before standalone
binaries and a published extension are available.

Before continuing, :doc:`install the language server </index>`. Building the
development extension also requires Node.js, npm and VS Code 1.91 or later.

Building the Extension
----------------------

Build and package the development extension from the repository root:

.. code-block:: terminal

    $ cd editor/vscode
    $ npm install
    $ npm run package

This creates ``editor/vscode/symfony-lsp-0.0.1.vsix``. Install it with VS Code:

.. code-block:: terminal

    $ code --install-extension editor/vscode/symfony-lsp-0.0.1.vsix

Restart VS Code after installation.

Configuration
-------------

Open the Symfony application as a VS Code workspace. Add these settings to the
workspace's ``.vscode/settings.json`` file:

.. code-block:: json

    {
        "symfonyLsp.serverPath": "/absolute/path/to/lsp/bin/symfony-lsp",
        "symfonyLsp.trustWorkspace": true,
        "symfonyLsp.phpCommand": ["php"],
        "symfonyLsp.environment": "dev",
        "symfonyLsp.debug": true,
        "php.suggest.basic": false
    }

``symfonyLsp.serverPath`` must be an absolute path. The server path is detected
automatically only when running the extension directly from this repository.

``symfonyLsp.trustWorkspace`` allows the server to execute application code.
Enable it only for code that you trust. See
:doc:`Symfony integrations </features/index>` for runtime indexing and
static-only behavior.

``symfonyLsp.phpCommand`` is an argument array used to run the project bridge.
For example, use ``["symfony", "php"]`` for Symfony CLI or
``["ddev", "exec", "php"]`` for DDEV. The command must be compatible with the
Symfony application.

``symfonyLsp.environment`` and ``symfonyLsp.debug`` select the Symfony runtime
whose effective metadata is indexed.

The PHP suggestion setting is optional. Symfony LSP is designed to coexist with
a general PHP language server such as Intelephense or PHP Tools. Keep that
server enabled for PHP diagnostics, types and general completion.

After changing a Symfony LSP setting, run ``Developer: Reload Window`` from the
VS Code command palette.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony LSP`` to inspect extension and
protocol messages. If the server doesn't start, verify that:

* ``symfonyLsp.serverPath`` points to an executable file;
* the extension was rebuilt after updating files under ``editor/vscode/``;
* the workspace settings contain a valid project PHP command.

Run ``composer install`` after updating server dependencies. Rebuild and
reinstall the VSIX after updating the extension.
