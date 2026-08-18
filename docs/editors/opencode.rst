Using Symfony Language Tools with OpenCode
==========================================

OpenCode can start Symfony Language Tools as a custom language server and make
Symfony-aware diagnostics and navigation available to its coding agent. Install
the standalone server, then configure it in the Symfony project.

Installing the Server
---------------------

Use the `standalone guide`_ to download a release and make ``symfony-lsp``
available on ``PATH``.

The OpenCode integration is supported on Linux and macOS.

Configuring OpenCode
--------------------

For a project whose code you trust, create ``opencode.json`` in the project
root:

.. code-block:: json

    {
        "$schema": "https://opencode.ai/config.json",
        "lsp": {
            "symfony": {
                "command": ["symfony-lsp"],
                "extensions": [
                    ".php",
                    ".twig",
                    ".yaml",
                    ".yml",
                    ".xml",
                    ".json",
                    ".js",
                    ".mjs",
                    ".ts",
                    ".env",
                    ".env.local"
                ],
                "initialization": {
                    "workspaceTrust": true
                }
            }
        }
    }

Use an absolute executable path in ``command`` when ``symfony-lsp`` isn't on
``PATH``. OpenCode starts the server when it accesses a file matching one of
the configured extensions. Add other project-specific dotenv suffixes, such as
``.env.dev``, when the agent needs to initiate requests from those files.

Keep OpenCode's general PHP language server enabled. OpenCode can connect all
matching language servers to the same PHP file, and Symfony Language Tools
complements the general server with Symfony-specific information.

Workspace Trust
---------------

OpenCode doesn't display the interactive workspace trust request sent by the
language server, so configure ``workspaceTrust`` explicitly. Set it to ``true``
only after reviewing and trusting the project because runtime indexing executes
application code.

Set ``workspaceTrust`` to ``false`` to keep the project in static-only mode.
Source-based navigation and diagnostics remain available, but features that
need effective runtime metadata are unavailable.

Supported Features
------------------

OpenCode can consume Symfony-aware diagnostics and use hover, go-to-definition
and find-references requests while its agent works on PHP, Twig, configuration
and frontend files.

OpenCode doesn't currently request completion, rename, code actions, document
links or code lenses from custom language servers. Use VS Code or Neovim when
you need those interactive editor features.

OpenCode sends watched-file notifications after its built-in editing tools
write a file, so Symfony Language Tools refreshes its source and runtime indexes
without restarting OpenCode. Files changed outside those tools are refreshed
the next time OpenCode accesses them through its LSP integration.

Configuration
-------------

OpenCode forwards the ``initialization`` object as Language Server Protocol
initialization options. Use it to override the default runtime configuration:

.. code-block:: json

    {
        "$schema": "https://opencode.ai/config.json",
        "lsp": {
            "symfony": {
                "command": ["symfony-lsp"],
                "extensions": [".php", ".twig", ".yaml", ".yml", ".xml"],
                "initialization": {
                    "workspaceTrust": true,
                    "phpCommand": ["symfony", "php"],
                    "containerProjectRoot": "",
                    "consolePath": "bin/console",
                    "environment": "dev",
                    "debug": true,
                    "runtimeIndexing": true,
                    "bridgeTimeout": 300,
                    "projectRoots": [],
                    "trace": "off"
                },
                "env": {
                    "SYMFONY_LSP_MEMORY_LIMIT": "4G"
                }
            }
        }
    }

``phpCommand``, ``containerProjectRoot``, ``consolePath``, ``environment``,
``debug``, ``runtimeIndexing``, ``bridgeTimeout``, ``projectRoots`` and
``trace`` have the same behavior as their VS Code counterparts, including
`Docker support`_. The
``env`` object configures environment variables for the server process.

Troubleshooting
---------------

Inspect the resolved OpenCode configuration first:

.. code-block:: terminal

    $ opencode debug config

Confirm that OpenCode can start the server and receive diagnostics for a file:

.. code-block:: terminal

    $ opencode debug lsp diagnostics src/Controller/HomeController.php

If runtime information is unavailable, verify that the project has Composer
dependencies installed, ``workspaceTrust`` is ``true`` and ``phpCommand`` can
run the application. Set ``trace`` to ``messages`` or ``verbose`` temporarily
for redacted protocol logging, then restore it to ``off``.

See the `OpenCode LSP documentation`_ for its custom language server settings
and debugging commands.

.. _`standalone guide`: ../index.rst#installing-a-standalone-release
.. _`Docker support`: ../docker.rst
.. _`OpenCode LSP documentation`: https://opencode.ai/docs/lsp/
