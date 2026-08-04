Using Symfony LSP with VS Code
==============================

The bundled VS Code extension configures the Symfony LSP client, workspace
trust, file associations and project settings. It requires VS Code 1.91 or
later.

Installing the Extension
------------------------

Download the ``.vsix`` file for the VS Code extension host's platform from the
GitHub release and install it:

.. code-block:: terminal

    $ code --install-extension /path/to/downloaded-extension.vsix

Packages are available for Linux x64 and ARM64, macOS x64 and ARM64 and Windows
x64. Each package contains the matching language server and Tree-sitter
sidecar, so ``symfonyLsp.serverPath`` doesn't need to be configured.

Building the Extension from Source
----------------------------------

Development builds require Node.js and npm. Build and package the extension
from the repository root:

.. code-block:: terminal

    $ make -C editor/vscode

This creates ``editor/vscode/symfony-lsp-0.3.0.vsix``. Source builds don't
contain a standalone server; configure ``symfonyLsp.serverPath`` before
installing this package.

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
bundle configuration, Messenger, Events, Security, AssetMapper, importmaps,
Stimulus, Live Components and Doctrine.
Installed extensions are disabled inside the test host to keep completion,
hover, navigation, diagnostics and code lens results deterministic. This doesn't
change the regular VS Code profile.

Index Status and Commands
-------------------------

The status bar shows the source and runtime index state for the application
owning the active document. It identifies indexing, static-only, stale and
failed states and displays the selected environment when both indexes are
ready. Select the status bar item to show details.

The command palette provides these commands:

* ``Symfony LSP: Refresh Index`` rebuilds source and runtime indexes;
* ``Symfony LSP: Show Index Status`` reports each discovered application;
* ``Symfony LSP: Switch Environment`` selects an environment and rebuilds its
  runtime index.

Configuration
-------------

Open the Symfony application as a VS Code workspace. Add these settings to the
workspace's ``.vscode/settings.json`` file:

.. code-block:: json

    {
        "symfonyLsp.phpCommand": ["php"],
        "symfonyLsp.consolePath": "bin/console",
        "symfonyLsp.environment": "dev",
        "symfonyLsp.debug": true,
        "symfonyLsp.runtimeIndexing": true,
        "symfonyLsp.projectRoots": [],
        "symfonyLsp.trace": "off",
        "symfonyLsp.translationDiagnostics": false,
        "php.suggest.basic": false
    }

``symfonyLsp.serverPath`` overrides the bundled executable and must be an
absolute path. Use it for a server built from source or a separately downloaded
standalone release.

The extension forwards VS Code's workspace trust decision to the server.
Untrusted workspaces remain in static-only mode. See
:doc:`Symfony integrations </features/index>` for runtime indexing and
static-only behavior.

``symfonyLsp.phpCommand`` is an argument array used to run the project bridge.
For example, use ``["symfony", "php"]`` for Symfony CLI or
``["ddev", "exec", "php"]`` for DDEV. The command must be compatible with the
Symfony application. ``symfonyLsp.consolePath`` selects the project console
used for normal cache maintenance.

``symfonyLsp.environment`` and ``symfonyLsp.debug`` select the Symfony runtime
whose effective metadata is indexed. ``symfonyLsp.runtimeIndexing`` can disable
application execution even in a trusted workspace.

``symfonyLsp.projectRoots`` can list application roots relative to the
workspace folder or as absolute paths. Leave it empty to discover nested
FrameworkBundle applications automatically.

``symfonyLsp.trace`` writes recursively redacted protocol traffic to the output
channel. It is disabled by default.

``symfonyLsp.translationDiagnostics`` enables missing-key diagnostics. It is a
resource-scoped setting and defaults to ``false``.

The PHP suggestion setting is optional. Symfony LSP is designed to coexist with
a general PHP language server such as Intelephense or PHP Tools. Keep that
server enabled for PHP diagnostics, types and general completion.

Run ``Developer: Reload Window`` after changing ``symfonyLsp.serverPath``. Use
``Symfony LSP: Switch Environment`` to change the active environment without
restarting the extension.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony LSP`` to inspect extension and
protocol messages. If the server doesn't start, verify that:

* the installed extension package matches the extension host's platform;
* a configured ``symfonyLsp.serverPath`` points to an executable file;
* the extension was rebuilt after updating files under ``editor/vscode/``;
* the workspace settings contain a valid project PHP command.

Run ``composer install`` after updating server dependencies. Rebuild and
reinstall the VSIX after updating the extension.
