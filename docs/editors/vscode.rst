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

    $ make -C editor/vscode

This creates ``editor/vscode/symfony-lsp-0.0.1.vsix``. Install it with VS Code:

.. code-block:: terminal

    $ code --install-extension editor/vscode/symfony-lsp-0.0.1.vsix

Restart VS Code after installation.

Twig Support
------------

The extension registers ``.twig`` and ``.html.twig`` files as the ``twig``
language so Symfony features work without another extension. It deliberately
doesn't provide generic Twig syntax highlighting, formatting or built-in symbol
completion.

Optional extensions can provide those editor features alongside Symfony LSP:

* Modern Twig (``Stanislav.vscode-twig``) provides generic Twig diagnostics,
  completion, hover and signature help;
* Twig Language 2 (``mblode.twig-language-2``) provides syntax highlighting,
  snippets, Emmet support and formatting;
* djLint (``monosans.djlint``) provides template formatting and linting.

Some extensions associate Twig files with the ``html`` language. Symfony LSP
also recognizes ``.twig`` files in that configuration.

Automated Extension Tests
-------------------------

The extension test suite launches an isolated VS Code Extension Development
Host against the Symfony runtime fixture. Install the fixture dependencies,
then run the tests:

.. code-block:: terminal

    $ cd tests/Fixtures/RuntimeApplication
    $ composer update
    $ cd ../../../editor/vscode
    $ npm ci
    $ npm run test:e2e

The first run downloads a matching VS Code build into
``editor/vscode/.vscode-test/``. The suite covers server lifecycle, routing,
dependency injection, Twig templates, translations, environment variables,
bundle configuration, Messenger, and Events. Installed extensions are disabled
inside
the test host to keep completion, hover, navigation, diagnostics and code lens
results deterministic. This doesn't change the regular VS Code profile.

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
        "symfonyLsp.translationDiagnostics": false,
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

``symfonyLsp.translationDiagnostics`` enables missing-key diagnostics. It is a
resource-scoped setting and defaults to ``false``.

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
