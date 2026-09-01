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
`Visual Studio Marketplace`_:

.. code-block:: terminal

    $ code --install-extension symfony.language-tools

Versions with a prerelease suffix are published separately on the prerelease
channel:

.. code-block:: terminal

    $ code --install-extension symfony.language-tools --pre-release

The Marketplace selects the package matching the extension host. Packages are
available for Linux x64 and ARM64, macOS x64 and ARM64 and Windows x64. Each
package contains the matching self-contained language server, so
``symfonyLsp.serverPath`` doesn't need to be configured.

You can also download the matching ``.vsix`` file from the GitHub release and
install it directly:

.. code-block:: terminal

    $ code --install-extension /path/to/downloaded-extension.vsix
    $ codium --install-extension /path/to/downloaded-extension.vsix

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
ready. Select the status bar item to show details, including when stale runtime
information was last updated.

The command palette provides these commands:

* ``Symfony Language Tools: Refresh Index`` rebuilds source and runtime indexes;
* ``Symfony Language Tools: Show Index Status`` reports each discovered
  application;
* ``Symfony Language Tools: Switch Environment`` selects an environment and
  rebuilds its runtime index.

Configuration
-------------

Open the Symfony application as a VS Code workspace. Put shared settings in
``.symfony-lsp.json``; see the `project configuration`_.

Use ``.vscode/settings.json`` for VS Code-specific settings or explicit editor
overrides:

.. code-block:: json

    {
        "symfonyLsp.memoryLimit": "4G",
        "symfonyLsp.trace": "off",
        "php.suggest.basic": false
    }

VS Code settings under ``symfonyLsp`` override values from
``.symfony-lsp.json`` for VS Code only.

``symfonyLsp.serverPath`` overrides the bundled executable and must be an
absolute path. Use it for a server built from source or a separately downloaded
standalone release.

``symfonyLsp.memoryLimit`` sets the PHP memory limit of the language server
process for large projects, for example ``4G`` or ``-1`` for no limit. Leave
it empty to keep the server default of ``2G``.

The extension forwards VS Code's workspace trust decision to the server.
Untrusted workspaces remain in static-only mode. See `Symfony integrations`_
for details.

Use `Docker support`_ when the PHP command runs in a container.
``symfonyLsp.trace`` adds redacted protocol messages to the output channel and
is disabled by default. The ``verbose`` level also records sanitized runtime
section failure causes with relative code locations and argument-free frames.

The PHP suggestion setting is optional. Symfony Language Tools is designed to
coexist with a general PHP language server such as Intelephense or PHP Tools.
Keep that server enabled for PHP diagnostics, types and general completion.

Run ``Developer: Reload Window`` after changing ``symfonyLsp.serverPath`` or
``symfonyLsp.memoryLimit``. Use
``Symfony Language Tools: Switch Environment`` to change the active environment
without restarting the extension.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony Language Tools`` to inspect startup,
configuration and indexing errors. If the server doesn't start, verify that:

* the installed extension package matches the extension host's platform;
* a configured ``symfonyLsp.serverPath`` points to an executable file;
* the workspace settings contain a valid project PHP command.

.. _`Symfony integrations`: ../features/index.rst
.. _`project configuration`: ../project-configuration.rst
.. _`Docker support`: ../docker.rst
.. _`Marketplace overview`: https://marketplace.visualstudio.com/items?itemName=symfony.language-tools
.. _`Visual Studio Marketplace`: https://marketplace.visualstudio.com/items?itemName=symfony.language-tools
