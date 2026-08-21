Using Symfony Language Tools with VS Code
=========================================

The bundled VS Code extension configures the Symfony Language Tools client,
workspace trust, file associations and project settings. It requires VS Code
1.91 or later.

The `Marketplace overview`_ walks through installation, indexing and core
features with the public Symfony Demo application, and includes an animated
tour of every supported integration and editor workflow.

Installing the Extension
------------------------

Install the stable Symfony Language Tools extension from the
`Visual Studio Marketplace`_ or `Open VSX Registry`_:

.. code-block:: terminal

    $ code --install-extension symfony.language-tools
    $ codium --install-extension symfony.language-tools

Versions with a prerelease suffix are published separately on the prerelease
channel:

.. code-block:: terminal

    $ code --install-extension symfony.language-tools --pre-release
    $ codium --install-extension symfony.language-tools --pre-release

The registry selects the package matching the extension host. Packages are
available for Linux x64 and ARM64, macOS x64 and ARM64 and Windows x64. Each
package contains the matching self-contained language server, so
``symfonyLsp.serverPath`` doesn't need to be configured.

You can also download the matching ``.vsix`` file from the GitHub release and
install it directly:

.. code-block:: terminal

    $ code --install-extension /path/to/downloaded-extension.vsix

Twig Support
------------

The extension registers ``.twig`` and ``.html.twig`` files as the ``twig``
language so Symfony features work without another extension. It deliberately
doesn't provide generic Twig syntax highlighting, formatting or built-in symbol
completion.

Optional extensions can provide those editor features alongside Symfony Language
Tools:

* Modern Twig (``Stanislav.vscode-twig``) provides generic Twig diagnostics,
  completion, hover and signature help;
* Twig Language 2 (``mblode.twig-language-2``) provides syntax highlighting,
  snippets, Emmet support and formatting;
* djLint (``monosans.djlint``) provides template formatting and linting.

Some extensions associate Twig files with the ``html`` language. Symfony
Language Tools also recognizes ``.twig`` files in that configuration.

Index Status and Commands
-------------------------

The status bar shows the source and runtime index state for the application
owning the active document. It identifies indexing, static-only, stale and
failed states and displays the selected environment when both indexes are
ready. Select the status bar item to show details.

The command palette provides these commands:

* ``Symfony Language Tools: Refresh Index`` rebuilds source and runtime indexes;
* ``Symfony Language Tools: Show Index Status`` reports each discovered
  application;
* ``Symfony Language Tools: Switch Environment`` selects an environment and
  rebuilds its runtime index.

Configuration
-------------

Open the Symfony application as a VS Code workspace. Add these settings to the
workspace's ``.vscode/settings.json`` file:

.. code-block:: json

    {
        "symfonyLsp.memoryLimit": "",
        "symfonyLsp.phpCommand": ["php"],
        "symfonyLsp.containerProjectRoot": "",
        "symfonyLsp.environment": "dev",
        "symfonyLsp.debug": true,
        "symfonyLsp.runtimeIndexing": true,
        "symfonyLsp.bridgeTimeout": 300,
        "symfonyLsp.projectRoots": [],
        "symfonyLsp.trace": "off",
        "symfonyLsp.translationDiagnostics": false,
        "php.suggest.basic": false
    }

``symfonyLsp.serverPath`` overrides the bundled executable and must be an
absolute path. Use it for a server built from source or a separately downloaded
standalone release.

``symfonyLsp.memoryLimit`` sets the PHP memory limit of the language server
process for large projects, for example ``4G`` or ``-1`` for no limit. Leave
it empty to keep the server default of ``2G``.

The extension forwards VS Code's workspace trust decision to the server.
Untrusted workspaces remain in static-only mode. See `Symfony integrations`_
for runtime indexing and static-only behavior.

``symfonyLsp.phpCommand`` is the argument array used to inspect the Symfony
application. For example, use ``["symfony", "php"]`` for Symfony CLI. The
command must be compatible with the Symfony application.
``symfonyLsp.containerProjectRoot`` supports a ``phpCommand`` that runs in a
Docker container: set it to the absolute project path inside the container.
See `Docker support`_ for the complete setup.

``symfonyLsp.environment`` selects the Symfony runtime whose effective metadata
is indexed. Runtime indexing requires ``symfonyLsp.debug`` to be ``true``.
``symfonyLsp.runtimeIndexing`` can disable application execution even in a
trusted workspace. ``symfonyLsp.bridgeTimeout`` sets the maximum duration in
seconds of each application bridge run; increase it when an application needs
more than the default 300 seconds to collect runtime metadata.

``symfonyLsp.projectRoots`` can list application roots relative to the
workspace folder or as absolute paths. Leave it empty to discover nested
FrameworkBundle applications automatically.

``symfonyLsp.trace`` writes recursively redacted protocol traffic to the output
channel. It is disabled by default.

``symfonyLsp.translationDiagnostics`` enables missing-key diagnostics. It is a
resource-scoped setting and defaults to ``false``.

The PHP suggestion setting is optional. Symfony Language Tools is designed to
coexist with a general PHP language server such as Intelephense or PHP Tools.
Keep that server enabled for PHP diagnostics, types and general completion.

Run ``Developer: Reload Window`` after changing ``symfonyLsp.serverPath`` or
``symfonyLsp.memoryLimit``. Use
``Symfony Language Tools: Switch Environment`` to change the active environment
without restarting the extension.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony Language Tools`` to inspect
extension and protocol messages. Startup records identify the extension and
server versions, platform and resolved executable. An
uncaught PHP failure is reported on a ``Symfony Language Tools failed:`` line
with its class, source location and redacted message; index status polling stops
when the language client is no longer running.

If the process exits without a ``Symfony Language Tools failed:`` line, inspect
the platform's native crash reports. On macOS, they are stored under
``~/Library/Logs/DiagnosticReports``. If the server doesn't start, verify that:

* the installed extension package matches the extension host's platform;
* a configured ``symfonyLsp.serverPath`` points to an executable file;
* the workspace settings contain a valid project PHP command.

.. _`Symfony integrations`: ../features/index.rst
.. _`Docker support`: ../docker.rst
.. _`Marketplace overview`: https://marketplace.visualstudio.com/items?itemName=symfony.language-tools
.. _`Visual Studio Marketplace`: https://marketplace.visualstudio.com/items?itemName=symfony.language-tools
.. _`Open VSX Registry`: https://open-vsx.org/extension/symfony/language-tools
